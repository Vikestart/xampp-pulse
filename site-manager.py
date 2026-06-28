#!/usr/bin/env python3
"""
site-manager.py - Desktop GUI to create, rename, and remove local XAMPP sites.

A CustomTkinter (light theme) dashboard: a live table of your sites with
Create / Rename / Remove actions and a log panel that streams the step-by-step
output as each operation runs.

Lifecycle each operation automates:
  - Create : docroot -> trusted SAN cert -> hosts -> :80/:443 vhosts -> test + restart
  - Rename : new domain (+ folder, CREATED if missing) -> new cert,
             swap hosts/vhosts/logs, drop old cert.  Old folder is never touched.
  - Remove : strip hosts + both vhosts, untrust + delete cert.  Folder is kept.

HARD SAFETY RULE: no operation ever deletes or renames an existing project folder.
Create and Rename may *create* a folder; Remove always keeps it.

Requires Administrator (hosts file + certutil). Self-elevates via UAC.

CLI (for scripting/tests; the double-clicked exe always opens the GUI):
    python site-manager.py create <domain> [--folder F] [--slug S] [--force] [--no-restart]
    python site-manager.py rename <old> <new> [--folder F] [--no-restart]
    python site-manager.py remove <domain> [--keep-cert] [--no-restart]
"""

import argparse
import ctypes
import datetime
import glob
import os
import queue
import re
import shutil
import subprocess
import sys
import threading
import webbrowser
from dataclasses import dataclass

# --- Environment paths (auto-detected so the tool is portable) ---------------
def _app_dir():
    """Folder this program runs from (the exe when frozen, else the .py)."""
    base = sys.executable if getattr(sys, "frozen", False) else __file__
    return os.path.dirname(os.path.abspath(base))


def _detect_xampp_root():
    """Find the XAMPP install regardless of drive/letter, so the exe can be
    shared. Walks up from this tool's location until it finds the XAMPP root
    (works whether the exe lives in \\htdocs, \\certs, a sub-folder, etc.),
    then falls back to the conventional C:\\xampp."""
    cur = _app_dir()
    for _ in range(8):
        if os.path.exists(os.path.join(cur, "apache", "bin", "httpd.exe")):
            return cur
        parent = os.path.dirname(cur)
        if parent == cur:
            break
        cur = parent
    return r"C:\xampp"


XAMPP_ROOT  = _detect_xampp_root()
XAMPP_FWD   = XAMPP_ROOT.replace("\\", "/")       # forward-slash form for Apache config
OPENSSL_EXE = os.path.join(XAMPP_ROOT, "apache", "bin", "openssl.exe")
HTTPD_EXE   = os.path.join(XAMPP_ROOT, "apache", "bin", "httpd.exe")
CERTS_DIR   = os.path.join(XAMPP_ROOT, "certs")
HTDOCS_DIR  = os.path.join(XAMPP_ROOT, "htdocs")
VHOSTS_CONF = os.path.join(XAMPP_ROOT, "apache", "conf", "extra", "httpd-vhosts.conf")
SSL_CONF    = os.path.join(XAMPP_ROOT, "apache", "conf", "extra", "httpd-ssl.conf")
HOSTS_FILE  = os.path.join(os.environ.get("SystemRoot", r"C:\Windows"),
                           "System32", "drivers", "etc", "hosts")
APACHE_SERVICE = "Apache2.4"
BACKUP_DIR  = os.path.join(XAMPP_ROOT, "site-manager-backups")

DOMAIN_RE = re.compile(r"^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$")
NAME_RE = re.compile(r"^[A-Za-z0-9._-]+$")


@dataclass
class Site:
    domain: str
    folder: str
    slug: str
    crt: str
    key: str


class OpError(Exception):
    """Recoverable error in one operation; surfaced to the user, not fatal."""


def fail(msg):
    raise OpError(msg)


# --- Logging sink (default prints; the GUI swaps in a queue) ------------------
class Log:
    _PREFIX = {"ok": "[ OK ]", "skip": "[ -- ]", "warn": "[ !! ]",
               "err": "[ XX ]", "info": "      ", "step": "===="}

    def __init__(self):
        self.sink = self._print

    def _print(self, level, msg):
        if level == "step":
            print(f"\n=== {msg} ===")
        else:
            print(f"{self._PREFIX.get(level, '')} {msg}")

    def emit(self, level, msg):
        try:
            self.sink(level, str(msg))
        except Exception:
            pass

    def ok(self, m):   self.emit("ok", m)
    def skip(self, m): self.emit("skip", m)
    def warn(self, m): self.emit("warn", m)
    def err(self, m):  self.emit("err", m)
    def info(self, m): self.emit("info", m)
    def step(self, m): self.emit("step", m)


LOG = Log()


# --- Subprocess helper (no flashing console windows) -------------------------
def _run(cmd, **kwargs):
    """Run a console tool capturing output, without popping a console window
    (this app is built --windowed, so child processes would otherwise flash)."""
    kwargs.setdefault("capture_output", True)
    kwargs.setdefault("text", True)
    if os.name == "nt":
        kwargs.setdefault("creationflags", 0x08000000)  # CREATE_NO_WINDOW
        si = subprocess.STARTUPINFO()
        si.dwFlags |= subprocess.STARTF_USESHOWWINDOW
        si.wShowWindow = 0  # SW_HIDE
        kwargs.setdefault("startupinfo", si)
    return subprocess.run(cmd, **kwargs)


# --- Validation --------------------------------------------------------------
def validate_domain(domain):
    domain = (domain or "").strip().lower()
    if not domain:
        fail("Please enter a domain.")
    if not domain.endswith(".localhost"):
        fail("Domain must end in “.localhost” (e.g. example.localhost).")
    if not DOMAIN_RE.match(domain):
        fail(f"“{domain}” is not a valid hostname.")
    return domain


def validate_name(name, kind):
    name = (name or "").strip()
    if not NAME_RE.match(name):
        fail(f"{kind} “{name}” may only contain letters, numbers, dot, dash, underscore.")
    return name


# --- Site discovery ----------------------------------------------------------
def list_sites():
    """Parse the :443 vhost blocks of httpd-ssl.conf into Site records."""
    with open(SSL_CONF, encoding="utf-8") as f:
        content = f.read()
    htdocs_norm = HTDOCS_DIR.replace("\\", "/").rstrip("/").lower()
    sites = []
    for m in re.finditer(r"<VirtualHost\b[^>]*>(.*?)</VirtualHost>", content, re.DOTALL):
        body = m.group(1)
        sn = re.search(r"^\s*ServerName\s+(\S+)\s*$", body, re.MULTILINE)
        dr = re.search(r'^\s*DocumentRoot\s+"?([^"\n]+?)"?\s*$', body, re.MULTILINE)
        if not sn or not dr:
            continue
        domain = sn.group(1).strip()
        docroot = dr.group(1).strip().replace("\\", "/").rstrip("/")
        if domain.lower().startswith("localhost") or docroot.lower() == htdocs_norm:
            continue
        folder = os.path.basename(docroot)
        el = re.search(r"logs/([A-Za-z0-9._-]+)_error\.log", body)
        slug = el.group(1) if el else folder
        sites.append(Site(domain, folder, slug,
                          os.path.join(CERTS_DIR, f"{domain}.crt"),
                          os.path.join(CERTS_DIR, f"{domain}.key")))
    sites.sort(key=lambda s: s.domain)
    return sites


def get_site(domain):
    domain = (domain or "").lower()
    for s in list_sites():
        if s.domain == domain:
            return s
    return None


def cli_get(domain):
    s = get_site(domain)
    if not s:
        fail(f"No managed site found for {domain}.")
    return s


def site_exists(domain):
    with open(SSL_CONF, encoding="utf-8") as f:
        content = f.read()
    return bool(re.search(rf"^\s*ServerName\s+{re.escape(domain)}\s*$", content, re.MULTILINE))


# --- Backups -----------------------------------------------------------------
def backup(path):
    """Copy a file into the consolidated BACKUP_DIR (never next to the original,
    so System32\\etc and the Apache conf folder stay clean)."""
    os.makedirs(BACKUP_DIR, exist_ok=True)
    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    dest = os.path.join(BACKUP_DIR, f"{os.path.basename(path)}.bak-{stamp}")
    shutil.copy2(path, dest)
    return dest


def _prune_backups(path, keep=5):
    """Keep only the newest `keep` backups of a file inside BACKUP_DIR."""
    pat = os.path.join(BACKUP_DIR, f"{os.path.basename(path)}.bak-*")
    for old in sorted(glob.glob(pat))[:-keep]:
        try:
            os.remove(old)
        except OSError:
            pass


def cleanup_legacy_backups():
    """Remove stray .bak files older builds left next to the originals
    (e.g. System32\\etc\\hosts.bak-*); backups now live in BACKUP_DIR."""
    for path in (HOSTS_FILE, VHOSTS_CONF, SSL_CONF):
        for old in glob.glob(f"{path}.bak-*"):
            try:
                os.remove(old)
            except OSError:
                pass


def backup_all():
    out = {}
    for p in (HOSTS_FILE, VHOSTS_CONF, SSL_CONF):
        out[p] = backup(p)
        _prune_backups(p)
    return out


def restore(backups, paths):
    for p in paths:
        b = backups.get(p)
        if b and os.path.exists(b):
            shutil.copy2(b, p)


# --- Document root -----------------------------------------------------------
def ensure_docroot(folder):
    path = os.path.join(HTDOCS_DIR, folder)
    if os.path.isdir(path):
        LOG.skip(f"DocumentRoot already exists: htdocs/{folder}")
        return
    os.makedirs(path, exist_ok=True)
    LOG.ok(f"Created DocumentRoot: htdocs/{folder}")
    if not os.listdir(path):
        index = os.path.join(path, "index.php")
        with open(index, "w", encoding="utf-8", newline="\n") as f:
            f.write(
                "<?php declare(strict_types=1);\n"
                "$host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'local site', ENT_QUOTES, 'UTF-8');\n"
                "?>\n"
                "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">\n"
                "<title>It works</title></head><body>\n"
                "<h1><?= $host ?></h1><p>Placeholder created by site-manager.</p>\n"
                "</body></html>\n"
            )
        LOG.ok("Added placeholder index.php")


# --- Certificate -------------------------------------------------------------
def _cnf_text(domain):
    return (
        "[req]\n"
        "default_bits = 2048\n"
        "prompt = no\n"
        "default_md = sha256\n"
        "req_extensions = req_ext\n"
        "x509_extensions = v3_req\n"
        "distinguished_name = dn\n\n"
        "[dn]\n"
        "C = US\nST = Local\nL = LocalHost\nO = XAMPP Dev\n"
        f"CN = {domain}\n\n"
        "[req_ext]\nsubjectAltName = @alt_names\n\n"
        "[v3_req]\nsubjectAltName = @alt_names\n\n"
        "[alt_names]\n"
        f"DNS.1 = {domain}\n"
    )


def generate_cert(domain, force=False):
    crt = os.path.join(CERTS_DIR, f"{domain}.crt")
    key = os.path.join(CERTS_DIR, f"{domain}.key")
    if os.path.exists(crt) and os.path.exists(key) and not force:
        LOG.skip(f"Certificate already exists: {domain}.crt")
        return
    cnf = os.path.join(CERTS_DIR, f"{domain}.cnf")
    with open(cnf, "w", encoding="ascii", newline="\n") as f:
        f.write(_cnf_text(domain))
    try:
        run = _run(
            [OPENSSL_EXE, "req", "-new", "-x509", "-newkey", "rsa:2048", "-sha256",
             "-nodes", "-keyout", key, "-days", "3650", "-out", crt, "-config", cnf],
            cwd=CERTS_DIR)
        if run.returncode != 0:
            fail(f"OpenSSL failed:\n{run.stderr or run.stdout}")
        LOG.ok(f"Generated certificate: {domain}.crt")
    finally:
        if os.path.exists(cnf):
            os.remove(cnf)
    trust = _run(["certutil", "-addstore", "-f", "Root", crt])
    if trust.returncode != 0:
        LOG.warn("Could not add cert to Trusted Root store (Administrator required).")
    else:
        LOG.ok("Certificate trusted in Windows Root store")


def untrust_and_delete_cert(domain):
    res = _run(["certutil", "-delstore", "Root", domain])
    if res.returncode == 0:
        LOG.ok(f"Untrusted certificate: {domain}")
    else:
        LOG.skip("Certificate was not in the Root store (nothing to untrust)")
    removed = []
    for p in (os.path.join(CERTS_DIR, f"{domain}.crt"), os.path.join(CERTS_DIR, f"{domain}.key")):
        if os.path.exists(p):
            os.remove(p)
            removed.append(os.path.basename(p))
    LOG.ok("Deleted cert files: " + ", ".join(removed)) if removed else LOG.skip("No cert files to delete")


# --- hosts file --------------------------------------------------------------
def add_hosts(domain):
    lines = open(HOSTS_FILE, encoding="utf-8").read().splitlines()
    entry_re = re.compile(rf"^\s*127\.0\.0\.1\s+{re.escape(domain)}\s*$")
    if any(entry_re.match(ln) for ln in lines):
        LOG.skip(f"hosts entry already present: {domain}")
        return
    localhost_re = re.compile(r"^\s*127\.0\.0\.1\s+\S+\.localhost\b")
    insert_at = None
    for i, ln in enumerate(lines):
        if localhost_re.match(ln):
            insert_at = i + 1
    new = f"127.0.0.1   {domain}"
    lines.append(new) if insert_at is None else lines.insert(insert_at, new)
    with open(HOSTS_FILE, "w", encoding="utf-8", newline="\r\n") as f:
        f.write("\n".join(lines) + "\n")
    LOG.ok(f"Added hosts entry: {new}")


def remove_hosts(domain):
    lines = open(HOSTS_FILE, encoding="utf-8").read().splitlines()
    entry_re = re.compile(rf"^\s*127\.0\.0\.1\s+{re.escape(domain)}\s*$")
    keep = [ln for ln in lines if not entry_re.match(ln)]
    if len(keep) == len(lines):
        LOG.skip(f"No hosts entry for {domain}")
        return
    with open(HOSTS_FILE, "w", encoding="utf-8", newline="\r\n") as f:
        f.write("\n".join(keep) + "\n")
    LOG.ok(f"Removed hosts entry: {domain}")


# --- Apache conf blocks ------------------------------------------------------
VHOST_BLOCK = """\
<VirtualHost *:80>
    ServerName {domain}
    DocumentRoot "{xampp}/htdocs/{folder}"

    Redirect permanent / https://{domain}/

    ErrorLog "{xampp}/apache/logs/{slug}-http-error.log"
    CustomLog "{xampp}/apache/logs/{slug}-http-access.log" common
</VirtualHost>"""

SSL_BLOCK = """\
<VirtualHost *:443>
    DocumentRoot "{xampp}/htdocs/{folder}"
    ServerName {domain}
    ErrorLog "${{SRVROOT}}/logs/{slug}_error.log"
    TransferLog "${{SRVROOT}}/logs/{slug}_access.log"

    SSLEngine on
    SSLCipherSuite ALL:!ADH:!EXPORTSRL:RC4+RSA:+HIGH:+MEDIUM:+LOW:+SSLv2:+EXP
    SSLHonorCipherOrder on

    SSLCertificateFile "{xampp}/certs/{domain}.crt"
    SSLCertificateKeyFile "{xampp}/certs/{domain}.key"

    <Directory "{xampp}/htdocs/{folder}">
        Options Indexes FollowSymLinks MultiViews
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>"""


def append_block(conf_path, block, domain):
    content = open(conf_path, encoding="utf-8").read()
    if re.search(rf"^\s*ServerName\s+{re.escape(domain)}\s*$", content, re.MULTILINE):
        LOG.skip(f"{os.path.basename(conf_path)} already has a block for {domain}")
        return
    sep = "" if content.endswith("\n\n") else ("\n" if content.endswith("\n") else "\n\n")
    with open(conf_path, "w", encoding="utf-8", newline="\n") as f:
        f.write(content + sep + "\n" + block + "\n")
    LOG.ok(f"Appended block to {os.path.basename(conf_path)}")


def remove_block(conf_path, domain):
    content = open(conf_path, encoding="utf-8").read()
    pattern = re.compile(r"[ \t]*<VirtualHost\b.*?</VirtualHost>[ \t]*\n?", re.DOTALL)
    removed = False

    def repl(match):
        nonlocal removed
        b = match.group(0)
        if re.search(rf"^\s*ServerName\s+{re.escape(domain)}\s*$", b, re.MULTILINE):
            removed = True
            return ""
        return b

    new = pattern.sub(repl, content)
    if not removed:
        LOG.skip(f"{os.path.basename(conf_path)} has no block for {domain}")
        return
    new = re.sub(r"\n{3,}", "\n\n", new).rstrip("\n") + "\n"
    with open(conf_path, "w", encoding="utf-8", newline="\n") as f:
        f.write(new)
    LOG.ok(f"Removed block from {os.path.basename(conf_path)}")


# --- Apache validation + restart --------------------------------------------
def config_test():
    res = _run([HTTPD_EXE, "-t"])
    return res.returncode == 0, (res.stderr or res.stdout).strip()


def restart_apache():
    _run(["net", "stop", APACHE_SERVICE])
    start = _run(["net", "start", APACHE_SERVICE])
    if start.returncode != 0:
        LOG.warn(f"Could not start {APACHE_SERVICE}. Start it from the XAMPP Control Panel.")
        return False
    return True


def validate_and_restart(backups, do_restart=True):
    passed, output = config_test()
    if not passed:
        LOG.warn("Apache config test FAILED — rolling back hosts & conf changes:")
        for line in output.splitlines():
            LOG.warn(line)
        restore(backups, [VHOSTS_CONF, SSL_CONF, HOSTS_FILE])
        fail("Aborted before restart. Any certificate change was left as-is (idempotent).")
    LOG.ok("Apache config test passed (Syntax OK)")
    if do_restart:
        if restart_apache():
            LOG.ok(f"Restarted {APACHE_SERVICE}")
    else:
        LOG.skip("Restart skipped.")


# --- Apache status / standalone actions -------------------------------------
def apache_status():
    out = _run(["sc", "query", APACHE_SERVICE]).stdout or ""
    if "RUNNING" in out:
        return "running"
    if "STOPPED" in out or "PENDING" in out:
        return "stopped"
    return "unknown"


def do_restart_apache():
    LOG.step("Restart Apache")
    if restart_apache():
        LOG.ok(f"Restarted {APACHE_SERVICE}")
    else:
        fail(f"Could not restart {APACHE_SERVICE}.")


def do_renew_cert(site):
    LOG.step("1/2  Re-issue certificate")
    _run(["certutil", "-delstore", "Root", site.domain])   # drop the old trusted copy
    generate_cert(site.domain, force=True)
    LOG.step("2/2  Reload Apache")
    if restart_apache():
        LOG.ok(f"Restarted {APACHE_SERVICE}")
    LOG.ok(f"Certificate renewed: https://{site.domain}/")


# --- Core operations (UI-free) ----------------------------------------------
def do_create(domain, folder, slug, force=False, do_restart=True):
    if site_exists(domain):
        fail(f"A site for {domain} already exists — use Rename or Remove.")
    LOG.step("1/5  Document root");        ensure_docroot(folder)
    LOG.step("2/5  SSL certificate");      generate_cert(domain, force)
    backups = backup_all()
    LOG.step("3/5  hosts file");           add_hosts(domain)
    LOG.step("4/5  Apache virtual hosts")
    append_block(VHOSTS_CONF, VHOST_BLOCK.format(xampp=XAMPP_FWD, domain=domain, folder=folder, slug=slug), domain)
    append_block(SSL_CONF, SSL_BLOCK.format(xampp=XAMPP_FWD, domain=domain, folder=folder, slug=slug), domain)
    LOG.step("5/5  Validate + restart Apache"); validate_and_restart(backups, do_restart)
    LOG.ok(f"Site ready: https://{domain}/")


def do_rename(site, new_domain, new_folder, do_restart=True):
    if new_domain == site.domain:
        fail("New domain is the same as the current one.")
    if site_exists(new_domain):
        fail(f"A site for {new_domain} already exists.")
    new_slug = new_folder if new_folder != site.folder else site.slug
    LOG.step("1/6  Document root");        ensure_docroot(new_folder)   # created if missing
    LOG.step("2/6  New SSL certificate");  generate_cert(new_domain, force=True)
    backups = backup_all()
    LOG.step("3/6  hosts file")
    remove_hosts(site.domain)
    add_hosts(new_domain)
    LOG.step("4/6  Apache virtual hosts")
    remove_block(VHOSTS_CONF, site.domain)
    remove_block(SSL_CONF, site.domain)
    append_block(VHOSTS_CONF, VHOST_BLOCK.format(xampp=XAMPP_FWD, domain=new_domain, folder=new_folder, slug=new_slug), new_domain)
    append_block(SSL_CONF, SSL_BLOCK.format(xampp=XAMPP_FWD, domain=new_domain, folder=new_folder, slug=new_slug), new_domain)
    LOG.step("5/6  Validate + restart Apache"); validate_and_restart(backups, do_restart)
    LOG.step("6/6  Remove old certificate"); untrust_and_delete_cert(site.domain)
    LOG.ok(f"Renamed to https://{new_domain}/  (old folder htdocs/{site.folder} left untouched)")


def do_remove(site, keep_cert=False, do_restart=True):
    backups = backup_all()
    LOG.step("1/4  hosts file");           remove_hosts(site.domain)
    LOG.step("2/4  Apache virtual hosts")
    remove_block(VHOSTS_CONF, site.domain)
    remove_block(SSL_CONF, site.domain)
    LOG.step("3/4  Validate + restart Apache"); validate_and_restart(backups, do_restart)
    LOG.step("4/4  Certificate")
    if keep_cert:
        LOG.skip("Keeping certificate (--keep-cert).")
    else:
        untrust_and_delete_cert(site.domain)
    LOG.ok(f"Removed {site.domain}. Project folder htdocs/{site.folder} was kept.")


# --- Elevation ---------------------------------------------------------------
def is_admin():
    try:
        return bool(ctypes.windll.shell32.IsUserAnAdmin())
    except Exception:
        return False


def relaunch_as_admin():
    target = sys.executable
    params = "" if getattr(sys, "frozen", False) else f'"{os.path.abspath(__file__)}" '
    params += subprocess.list2cmdline(sys.argv[1:])
    rc = ctypes.windll.shell32.ShellExecuteW(None, "runas", target, params, None, 1)
    if rc <= 32:
        _fatal("Administrator rights are required. UAC was cancelled or failed.")
    sys.exit(0)


def _fatal(msg):
    try:
        ctypes.windll.user32.MessageBoxW(0, str(msg), "XAMPP Site Manager", 0x10)
    except Exception:
        print(f"[FATAL] {msg}")
    sys.exit(1)


# =============================================================================
#  GUI
# =============================================================================
def run_gui():
    import customtkinter as ctk
    import tkinter as tk
    from tkinter import ttk

    ctk.set_appearance_mode("light")
    ctk.set_default_color_theme("blue")

    CARD = "#ffffff"
    INK = "#1f2937"
    MUTED = "#6b7280"
    ACCENT = "#2563eb"
    DANGER = "#dc2626"
    GREY = "#cbd5e1"        # disabled button background
    GREY_TEXT = "#94a3b8"   # disabled button label
    SPIN = "⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏"   # braille spinner frames

    def center(win, w, h):
        win.update_idletasks()
        x = win.winfo_screenwidth() // 2 - w // 2
        y = win.winfo_screenheight() // 2 - h // 2
        win.geometry(f"{w}x{h}+{x}+{y}")

    class Dialog(ctk.CTkToplevel):
        """Base modal dialog."""
        def __init__(self, master, title, w=460, h=300):
            super().__init__(master)
            self.title(title)
            self.resizable(False, False)
            self.configure(fg_color="#f3f4f6")
            center(self, w, h)
            self.transient(master)
            self.after(10, self.grab_set)
            self.bind("<Escape>", lambda e: self.destroy())

        def field(self, parent, label, placeholder, default=""):
            ctk.CTkLabel(parent, text=label, anchor="w", text_color=INK,
                         font=("Segoe UI", 12, "bold")).pack(fill="x", padx=24, pady=(10, 2))
            var = tk.StringVar(value=default)
            entry = ctk.CTkEntry(parent, textvariable=var, placeholder_text=placeholder,
                                 height=34, fg_color="#ffffff", text_color=INK, border_color="#d1d5db")
            entry.pack(fill="x", padx=24)
            return var, entry

    class CreateDialog(Dialog):
        def __init__(self, master, on_submit):
            super().__init__(master, "Create a new site", 480, 440)
            self.on_submit = on_submit
            # action bar packed first so it is always visible
            row = ctk.CTkFrame(self, fg_color="transparent")
            row.pack(fill="x", padx=24, pady=(6, 18), side="bottom")
            ctk.CTkButton(row, text="Create site", width=140, fg_color=ACCENT,
                          hover_color="#1d4ed8", command=self._submit).pack(side="right")
            ctk.CTkButton(row, text="Cancel", width=110, fg_color="#e5e7eb", text_color=INK,
                          hover_color="#d1d5db", command=self.destroy).pack(side="right", padx=(0, 10))
            self.err = ctk.CTkLabel(self, text="", text_color=DANGER, anchor="w",
                                    font=("Segoe UI", 11), wraplength=430, justify="left")
            self.err.pack(fill="x", padx=24, pady=(0, 2), side="bottom")
            ctk.CTkLabel(self, text="Create a new site", text_color=INK,
                         font=("Segoe UI", 18, "bold")).pack(anchor="w", padx=24, pady=(18, 2))
            self.domain_var, dom = self.field(self, "Local domain", "example.localhost")
            self.folder_var, _ = self.field(self, "DocumentRoot folder  (blank = from domain)", "example")
            self.slug_var, _ = self.field(self, "Log slug  (blank = same as folder)", "example")
            dom.focus_set()
            self.bind("<Return>", lambda e: self._submit())

        def _submit(self):
            try:
                domain = validate_domain(self.domain_var.get())
                folder = validate_name(self.folder_var.get().strip() or domain.split(".")[0], "Folder")
                slug = validate_name(self.slug_var.get().strip() or folder, "Slug")
            except OpError as e:
                self.err.configure(text=str(e))
                return
            except Exception as e:
                self.err.configure(text=f"Error: {e}")
                return
            cb = self.on_submit
            self.destroy()
            cb(domain, folder, slug)

    class RenameDialog(Dialog):
        def __init__(self, master, site, on_submit):
            super().__init__(master, "Rename a site", 480, 440)
            self.site = site
            self.on_submit = on_submit
            # action bar packed first so it is always visible
            row = ctk.CTkFrame(self, fg_color="transparent")
            row.pack(fill="x", padx=24, pady=(6, 18), side="bottom")
            ctk.CTkButton(row, text="Rename", width=140, fg_color=ACCENT,
                          hover_color="#1d4ed8", command=self._submit).pack(side="right")
            ctk.CTkButton(row, text="Cancel", width=110, fg_color="#e5e7eb", text_color=INK,
                          hover_color="#d1d5db", command=self.destroy).pack(side="right", padx=(0, 10))
            self.err = ctk.CTkLabel(self, text="", text_color=DANGER, anchor="w",
                                    font=("Segoe UI", 11), wraplength=430, justify="left")
            self.err.pack(fill="x", padx=24, pady=(0, 2), side="bottom")
            ctk.CTkLabel(self, text=f"Rename  {site.domain}", text_color=INK,
                         font=("Segoe UI", 18, "bold")).pack(anchor="w", padx=24, pady=(18, 0))
            ctk.CTkLabel(self, text=f"Currently → htdocs/{site.folder}", text_color=MUTED,
                         font=("Segoe UI", 11)).pack(anchor="w", padx=24)
            self.domain_var, dom = self.field(self, "New domain", "new-name.localhost")
            self.folder_var, _ = self.field(self, "DocumentRoot folder  (created if missing)", "", default=site.folder)
            dom.focus_set()
            self.bind("<Return>", lambda e: self._submit())

        def _submit(self):
            try:
                new_domain = validate_domain(self.domain_var.get())
                new_folder = validate_name(self.folder_var.get().strip() or self.site.folder, "Folder")
                if new_domain == self.site.domain:
                    fail("New domain is the same as the current one.")
                if site_exists(new_domain):
                    fail(f"A site for {new_domain} already exists.")
            except OpError as e:
                self.err.configure(text=str(e))
                return
            except Exception as e:
                self.err.configure(text=f"Error: {e}")
                return
            cb = self.on_submit
            self.destroy()
            cb(self.site, new_domain, new_folder)

    class RemoveDialog(Dialog):
        def __init__(self, master, site, on_confirm):
            super().__init__(master, "Remove a site", 480, 330)
            # action bar packed first so it is always visible
            row = ctk.CTkFrame(self, fg_color="transparent")
            row.pack(fill="x", padx=24, pady=(6, 18), side="bottom")
            ctk.CTkButton(row, text="Remove site", width=140, fg_color=DANGER,
                          hover_color="#b91c1c",
                          command=lambda: (self.destroy(), on_confirm(site))).pack(side="right")
            ctk.CTkButton(row, text="Cancel", width=110, fg_color="#e5e7eb", text_color=INK,
                          hover_color="#d1d5db", command=self.destroy).pack(side="right", padx=(0, 10))
            ctk.CTkLabel(self, text=f"Remove  {site.domain}?", text_color=DANGER,
                         font=("Segoe UI", 18, "bold")).pack(anchor="w", padx=24, pady=(20, 6))
            ctk.CTkLabel(self,
                         text=(f"This removes the hosts entry, both vhost blocks and the\n"
                               f"SSL certificate for {site.domain}.\n\n"
                               f"The project folder {XAMPP_FWD}/htdocs/{site.folder}\n"
                               f"will NOT be deleted — remove it manually if you want to."),
                         text_color=INK, justify="left", font=("Segoe UI", 12)).pack(anchor="w", padx=24)

    class App(ctk.CTk):
        def __init__(self):
            super().__init__()
            self.title("XAMPP Site Manager")
            self.configure(fg_color="#eef1f5")
            center(self, 940, 680)
            self.minsize(820, 600)
            self.busy = False
            self.log_queue = queue.Queue()
            self.sites = {}
            self.all_sites = []
            LOG.sink = lambda level, msg: self.log_queue.put((level, msg))
            self._build()
            self.refresh_sites()
            self.after(80, self._drain)

        # ---- layout ----
        def _build(self):
            self.grid_columnconfigure(0, weight=1)
            self.grid_rowconfigure(1, weight=3)
            self.grid_rowconfigure(3, weight=2)

            header = ctk.CTkFrame(self, fg_color="transparent")
            header.grid(row=0, column=0, sticky="ew", padx=20, pady=(18, 8))
            header.grid_columnconfigure(0, weight=1)
            ctk.CTkLabel(header, text="🌐  XAMPP Site Manager", text_color=INK,
                         font=("Segoe UI", 22, "bold")).grid(row=0, column=0, sticky="w")
            ctk.CTkLabel(header, text="Manage your local .localhost development sites",
                         text_color=MUTED, font=("Segoe UI", 12)).grid(row=1, column=0, sticky="w")
            hr = ctk.CTkFrame(header, fg_color="transparent")
            hr.grid(row=0, column=1, rowspan=2, sticky="e")
            self.apache_pill = ctk.CTkLabel(hr, text="●  Apache: …", text_color=MUTED,
                                            font=("Segoe UI", 12, "bold"))
            self.apache_pill.pack(side="left", padx=(0, 14))
            self.btn_restart = ctk.CTkButton(hr, text="⟲  Restart Apache", width=150, height=34,
                                             fg_color="#f59e0b", hover_color="#d97706",
                                             text_color="#ffffff", font=("Segoe UI", 12, "bold"),
                                             command=self._restart_apache)
            self.btn_restart.pack(side="left", padx=(0, 8))
            ctk.CTkButton(hr, text="⟳  Refresh", width=100, height=34, fg_color="#e5e7eb",
                          text_color=INK, hover_color="#d1d5db",
                          command=self.refresh_sites).pack(side="left")

            # sites table
            card = ctk.CTkFrame(self, fg_color=CARD, corner_radius=12,
                                border_width=1, border_color="#e2e8f0")
            card.grid(row=1, column=0, sticky="nsew", padx=20, pady=8)
            card.grid_columnconfigure(0, weight=1)
            card.grid_rowconfigure(1, weight=1)

            thead = ctk.CTkFrame(card, fg_color="transparent")
            thead.grid(row=0, column=0, columnspan=2, sticky="ew", padx=12, pady=(10, 2))
            self.table_title = ctk.CTkLabel(thead, text="Your sites", text_color=INK,
                                            font=("Segoe UI", 13, "bold"))
            self.table_title.pack(side="left")
            self.search_var = tk.StringVar()
            self.search_var.trace_add("write", lambda *a: self._render_table())
            ctk.CTkEntry(thead, textvariable=self.search_var, width=220, height=30,
                         placeholder_text="🔍  Filter sites…", fg_color="#ffffff",
                         text_color=INK, border_color="#d1d5db").pack(side="right")

            style = ttk.Style()
            try:
                style.theme_use("clam")
            except Exception:
                pass
            style.configure("Sites.Treeview", background=CARD, fieldbackground=CARD,
                            foreground=INK, rowheight=34, borderwidth=0, font=("Segoe UI", 11))
            style.configure("Sites.Treeview.Heading", background="#eaf0f9", foreground="#1e3a5f",
                            font=("Segoe UI", 11, "bold"), relief="flat", padding=6)
            style.map("Sites.Treeview", background=[("selected", ACCENT)],
                      foreground=[("selected", "#ffffff")])

            self.tree = ttk.Treeview(card, style="Sites.Treeview", show="headings",
                                     columns=("domain", "docroot", "slug", "cert"))
            for col, txt, w, anchor in (("domain", "Domain", 260, "w"),
                                        ("docroot", "DocumentRoot", 240, "w"),
                                        ("slug", "Log slug", 140, "w"),
                                        ("cert", "Cert", 70, "center")):
                self.tree.heading(col, text=txt, anchor=anchor)
                self.tree.column(col, width=w, anchor=anchor)
            self.tree.tag_configure("oddrow", background="#f6f9fc")
            self.tree.tag_configure("evenrow", background="#ffffff")
            self.tree.tag_configure("empty", foreground=MUTED)
            self.tree.tag_configure("missing", foreground=DANGER)
            self.tree.grid(row=1, column=0, sticky="nsew", padx=8, pady=(0, 8))
            sb = ctk.CTkScrollbar(card, command=self.tree.yview, fg_color="transparent",
                                  button_color="#cbd5e1", button_hover_color="#94a3b8")
            self.tree.configure(yscrollcommand=sb.set)
            sb.grid(row=1, column=1, sticky="ns", pady=(2, 10), padx=(0, 6))
            self.tree.bind("<<TreeviewSelect>>", lambda e: self._update_buttons())
            self.tree.bind("<Button-1>", self._on_click)
            self.tree.bind("<Double-1>", self._on_double)

            # "working" overlay that covers the list during an operation
            self.overlay = ctk.CTkFrame(card, fg_color="#f8fafc", corner_radius=12)
            self.spin_lbl = ctk.CTkLabel(self.overlay, text="", font=("Segoe UI", 36, "bold"),
                                         text_color=ACCENT)
            self.spin_lbl.place(relx=0.5, rely=0.42, anchor="center")
            ctk.CTkLabel(self.overlay, text="Working…", font=("Segoe UI", 15, "bold"),
                         text_color=INK).place(relx=0.5, rely=0.58, anchor="center")

            # action buttons — primary (left) and per-site secondary (right)
            bar = ctk.CTkFrame(self, fg_color="transparent")
            bar.grid(row=2, column=0, sticky="ew", padx=20, pady=(2, 8))
            self.btn_create = ctk.CTkButton(bar, text="＋  Create new site", width=170, height=38,
                                            fg_color=ACCENT, hover_color="#1d4ed8",
                                            font=("Segoe UI", 13, "bold"), command=self._create)
            self.btn_create.pack(side="left")
            self.btn_rename = ctk.CTkButton(bar, text="✎  Rename", width=120, height=38,
                                            fg_color="#0ea5e9", hover_color="#0284c7",
                                            font=("Segoe UI", 13, "bold"), command=self._rename)
            self.btn_rename.pack(side="left", padx=10)
            self.btn_remove = ctk.CTkButton(bar, text="🗑  Remove", width=120, height=38,
                                            fg_color=DANGER, hover_color="#b91c1c",
                                            font=("Segoe UI", 13, "bold"), command=self._remove)
            self.btn_remove.pack(side="left")
            self.btn_renew = ctk.CTkButton(bar, text="🔒  Renew cert", width=130, height=38,
                                           font=("Segoe UI", 12), command=self._renew)
            self.btn_renew.pack(side="right")
            self.btn_folder = ctk.CTkButton(bar, text="📂  Open folder", width=130, height=38,
                                            font=("Segoe UI", 12), command=self._open_folder)
            self.btn_folder.pack(side="right", padx=10)
            self.btn_open = ctk.CTkButton(bar, text="🌐  Open site", width=125, height=38,
                                          font=("Segoe UI", 12), command=self._open_site)
            self.btn_open.pack(side="right")

            # log panel
            logcard = ctk.CTkFrame(self, fg_color=CARD, corner_radius=12,
                                   border_width=1, border_color="#e2e8f0")
            logcard.grid(row=3, column=0, sticky="nsew", padx=20, pady=(8, 6))
            logcard.grid_columnconfigure(0, weight=1)
            logcard.grid_rowconfigure(1, weight=1)
            loghdr = ctk.CTkFrame(logcard, fg_color="transparent")
            loghdr.grid(row=0, column=0, columnspan=2, sticky="ew", padx=10, pady=(8, 0))
            ctk.CTkLabel(loghdr, text="Activity log", text_color=MUTED,
                         font=("Segoe UI", 11, "bold")).pack(side="left")
            ctk.CTkButton(loghdr, text="Clear", width=62, height=26, fg_color="#e5e7eb",
                          text_color=INK, hover_color="#d1d5db", font=("Segoe UI", 11),
                          command=self._clear_log).pack(side="right")
            ctk.CTkButton(loghdr, text="Copy", width=62, height=26, fg_color="#e5e7eb",
                          text_color=INK, hover_color="#d1d5db", font=("Segoe UI", 11),
                          command=self._copy_log).pack(side="right", padx=(0, 8))
            self.logtext = tk.Text(logcard, height=8, wrap="word", relief="flat",
                                   background="#fbfcfe", foreground=INK, font=("Consolas", 10),
                                   padx=10, pady=8, state="disabled", borderwidth=0)
            self.logtext.grid(row=1, column=0, sticky="nsew", padx=10, pady=(2, 10))
            lsb = ctk.CTkScrollbar(logcard, command=self.logtext.yview, fg_color="transparent",
                                   button_color="#cbd5e1", button_hover_color="#94a3b8")
            self.logtext.configure(yscrollcommand=lsb.set)
            lsb.grid(row=1, column=1, sticky="ns", pady=(2, 10), padx=(0, 6))
            for tag, color in (("ok", "#15803d"), ("skip", "#6b7280"), ("warn", "#b45309"),
                               ("err", "#b91c1c"), ("info", "#6b7280")):
                self.logtext.tag_config(tag, foreground=color)
            self.logtext.tag_config("step", foreground="#1d4ed8", font=("Consolas", 10, "bold"))

            # footer: detected environment (left) + live status (right)
            footer = ctk.CTkFrame(self, fg_color="transparent")
            footer.grid(row=4, column=0, sticky="ew", padx=22, pady=(0, 10))
            ctk.CTkLabel(footer, text=f"📁  XAMPP: {XAMPP_FWD}      ⚙  Service: {APACHE_SERVICE}",
                         text_color=MUTED, font=("Segoe UI", 11)).pack(side="left")
            link = ctk.CTkLabel(footer, text="©  Aleksander Støle", text_color=ACCENT,
                                font=("Segoe UI", 11, "underline"), cursor="hand2")
            link.pack(side="left", padx=(28, 0))
            link.bind("<Button-1>", lambda e: webbrowser.open("https://astole.me"))
            self.status = ctk.CTkLabel(footer, text="", text_color=MUTED, font=("Segoe UI", 12, "bold"))
            self.status.pack(side="right")

            self._update_buttons()
            self._append("info", "Ready.")

        # ---- helpers ----
        def _selected(self):
            sel = self.tree.selection()
            return self.sites.get(sel[0]) if sel else None

        def _on_click(self, event):
            # Clicking empty space (or the placeholder row) deselects.
            row = self.tree.identify_row(event.y)
            if not row or row not in self.sites:
                sel = self.tree.selection()
                if sel:
                    self.tree.selection_remove(*sel)
                self._update_buttons()

        def _on_double(self, event):
            row = self.tree.identify_row(event.y)
            if row and row in self.sites and not self.busy:
                self._rename()

        @staticmethod
        def _style_btn(btn, enabled, fg, hover):
            if enabled:
                btn.configure(state="normal", fg_color=fg, hover_color=hover, text_color="#ffffff")
            else:
                btn.configure(state="disabled", fg_color=GREY, hover_color=GREY, text_color=GREY_TEXT)

        @staticmethod
        def _style_btn2(btn, enabled):
            # neutral (light) secondary button
            if enabled:
                btn.configure(state="normal", fg_color="#e5e7eb", hover_color="#d1d5db", text_color=INK)
            else:
                btn.configure(state="disabled", fg_color="#eef1f5", hover_color="#eef1f5", text_color=GREY_TEXT)

        def _update_buttons(self):
            ready = not self.busy
            has = self._selected() is not None and ready
            self._style_btn(self.btn_create, ready, ACCENT, "#1d4ed8")
            self._style_btn(self.btn_rename, has, "#0ea5e9", "#0284c7")
            self._style_btn(self.btn_remove, has, DANGER, "#b91c1c")
            self._style_btn(self.btn_restart, ready, "#f59e0b", "#d97706")
            for b in (self.btn_open, self.btn_folder, self.btn_renew):
                self._style_btn2(b, has)

        def refresh_sites(self):
            try:
                self.all_sites = list_sites()
            except Exception as e:
                self.all_sites = []
                self._set_status(f"Could not read config: {e}", DANGER)
            self._render_table()
            self._refresh_apache_status()

        def _render_table(self):
            self.tree.delete(*self.tree.get_children())
            self.sites = {}
            flt = (self.search_var.get() if hasattr(self, "search_var") else "").strip().lower()
            shown = [s for s in self.all_sites
                     if not flt or flt in s.domain.lower() or flt in s.folder.lower()]
            for i, s in enumerate(shown):
                missing = not os.path.isdir(os.path.join(HTDOCS_DIR, s.folder))
                cert = "✓" if os.path.exists(s.crt) else "✗"
                docroot = f"htdocs/{s.folder}" + ("   ⚠ missing" if missing else "")
                tags = ["evenrow" if i % 2 == 0 else "oddrow"]
                if missing:
                    tags.append("missing")
                iid = self.tree.insert("", "end", values=(s.domain, docroot, s.slug, cert), tags=tuple(tags))
                self.sites[iid] = s
            if not shown:
                msg = ("No sites match your filter." if flt else
                       "No sites yet — click  ＋ Create new site  to add one.")
                self.tree.insert("", "end", tags=("empty",), values=(msg, "", "", ""))
            if hasattr(self, "table_title"):
                total = len(self.all_sites)
                self.table_title.configure(text=f"Your sites  ·  {total}" if total else "Your sites")
            self._update_buttons()

        def _refresh_apache_status(self):
            st = apache_status()
            colors = {"running": "#15803d", "stopped": DANGER, "unknown": MUTED}
            labels = {"running": "●  Apache: running", "stopped": "●  Apache: stopped",
                      "unknown": "●  Apache: unknown"}
            if hasattr(self, "apache_pill"):
                self.apache_pill.configure(text=labels[st], text_color=colors[st])

        def _set_status(self, text, color):
            self.status.configure(text=text, text_color=color)

        def _start_spin(self):
            self.overlay.place(relx=0, rely=0, relwidth=1, relheight=1)
            self.overlay.lift()
            self._spin_on = True
            self._spin_i = 0
            self._spin_tick()

        def _spin_tick(self):
            if not getattr(self, "_spin_on", False):
                return
            ch = SPIN[self._spin_i % len(SPIN)]
            self._spin_i += 1
            self.spin_lbl.configure(text=ch)
            self._set_status(f"{ch}  Working…", ACCENT)
            self.after(110, self._spin_tick)

        def _stop_spin(self):
            self._spin_on = False
            try:
                self.overlay.place_forget()
            except Exception:
                pass

        def _append(self, level, msg):
            self.logtext.configure(state="normal")
            if level == "step":
                self.logtext.insert("end", f"\n── {msg} ──\n", "step")
            else:
                glyph = {"ok": "✓", "skip": "‣", "warn": "⚠", "err": "✗", "info": " "}.get(level, " ")
                self.logtext.insert("end", f"  {glyph}  {msg}\n", level)
            self.logtext.see("end")
            self.logtext.configure(state="disabled")

        def _clear_log(self):
            self.logtext.configure(state="normal")
            self.logtext.delete("1.0", "end")
            self.logtext.configure(state="disabled")

        def _copy_log(self):
            txt = self.logtext.get("1.0", "end").strip()
            if not txt:
                return
            self.clipboard_clear()
            self.clipboard_append(txt)
            self._set_status("Activity log copied to clipboard", "#15803d")

        # ---- run a backend op on a worker thread ----
        def _run(self, thunk, ok_text):
            if self.busy:
                return
            self.busy = True
            self._update_buttons()
            self._clear_log()
            self._start_spin()

            def worker():
                try:
                    thunk()
                    self.log_queue.put(("__done__", (True, ok_text)))
                except OpError as e:
                    LOG.err(str(e))
                    self.log_queue.put(("__done__", (False, str(e))))
                except Exception as e:
                    LOG.err(f"Unexpected error: {e}")
                    self.log_queue.put(("__done__", (False, str(e))))

            threading.Thread(target=worker, daemon=True).start()

        def _drain(self):
            try:
                while True:
                    level, payload = self.log_queue.get_nowait()
                    if level == "__done__":
                        ok, text = payload
                        self.busy = False
                        self._stop_spin()
                        self.refresh_sites()
                        self._set_status(("✓  " if ok else "✗  ") + text,
                                         "#15803d" if ok else DANGER)
                    else:
                        self._append(level, payload)
            except queue.Empty:
                pass
            self.after(80, self._drain)

        # ---- button handlers ----
        def _create(self):
            if self.busy:
                return
            CreateDialog(self, on_submit=lambda d, f, s: self._run(
                lambda: do_create(d, f, s, force=False, do_restart=True),
                f"{d} is ready at https://{d}/"))

        def _rename(self):
            site = self._selected()
            if not site or self.busy:
                return
            RenameDialog(self, site, on_submit=lambda st, nd, nf: self._run(
                lambda: do_rename(st, nd, nf, do_restart=True),
                f"Renamed to {nd} — old folder htdocs/{st.folder} kept"))

        def _remove(self):
            site = self._selected()
            if not site or self.busy:
                return
            RemoveDialog(self, site, on_confirm=lambda st: self._run(
                lambda: do_remove(st, keep_cert=False, do_restart=True),
                f"Removed {st.domain} — folder htdocs/{st.folder} kept"))

        def _open_site(self):
            s = self._selected()
            if not s:
                return
            webbrowser.open(f"https://{s.domain}/")
            self._set_status(f"Opened https://{s.domain}/", "#15803d")

        def _open_folder(self):
            s = self._selected()
            if not s:
                return
            path = os.path.join(HTDOCS_DIR, s.folder)
            if not os.path.isdir(path):
                self._set_status(f"Folder does not exist: htdocs/{s.folder}", DANGER)
                return
            try:
                os.startfile(path)
                self._set_status(f"Opened htdocs/{s.folder}", "#15803d")
            except Exception as e:
                self._set_status(f"Could not open folder: {e}", DANGER)

        def _renew(self):
            s = self._selected()
            if not s or self.busy:
                return
            self._run(lambda: do_renew_cert(s), f"Certificate renewed for {s.domain}")

        def _restart_apache(self):
            if self.busy:
                return
            self._run(do_restart_apache, f"{APACHE_SERVICE} restarted")

    App().mainloop()


# --- CLI ---------------------------------------------------------------------
def run_cli(args):
    if args.cmd == "create":
        domain = validate_domain(args.domain)
        folder = validate_name(args.folder or domain.split(".")[0], "Folder")
        slug = validate_name(args.slug or folder, "Slug")
        do_create(domain, folder, slug, args.force, not args.no_restart)
    elif args.cmd == "rename":
        site = cli_get(args.old)
        new_domain = validate_domain(args.new)
        new_folder = validate_name(args.folder or site.folder, "Folder")
        do_rename(site, new_domain, new_folder, not args.no_restart)
    elif args.cmd == "remove":
        site = cli_get(args.domain)
        do_remove(site, args.keep_cert, not args.no_restart)


def parse_args():
    p = argparse.ArgumentParser(prog="site-manager",
                                description="Create, rename, and remove local XAMPP sites.")
    sub = p.add_subparsers(dest="cmd")
    c = sub.add_parser("create"); c.add_argument("domain"); c.add_argument("--folder")
    c.add_argument("--slug"); c.add_argument("--force", action="store_true"); c.add_argument("--no-restart", action="store_true")
    r = sub.add_parser("rename"); r.add_argument("old"); r.add_argument("new")
    r.add_argument("--folder"); r.add_argument("--no-restart", action="store_true")
    d = sub.add_parser("remove"); d.add_argument("domain")
    d.add_argument("--keep-cert", action="store_true"); d.add_argument("--no-restart", action="store_true")
    return p.parse_args()


def main():
    if os.name != "nt":
        print("This tool is Windows-only.")
        sys.exit(1)

    args = parse_args()

    if not is_admin():
        relaunch_as_admin()

    missing = [p for p in (OPENSSL_EXE, HTTPD_EXE, HOSTS_FILE, VHOSTS_CONF, SSL_CONF) if not os.path.exists(p)]
    if missing:
        _fatal("Required path(s) not found:\n" + "\n".join(missing))

    cleanup_legacy_backups()

    if args.cmd:
        try:
            run_cli(args)
        except OpError as e:
            LOG.err(str(e))
            sys.exit(1)
    else:
        run_gui()


if __name__ == "__main__":
    main()
