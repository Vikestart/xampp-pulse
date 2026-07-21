# XAMPP Pulse expansion — progress

Decisions: phased · passphrase unlock · bundled PHP mail catcher · single PHP.

## Phase 0 — Security foundation  ✅ DONE (verified)
- [x] `lib/auth.php` — passphrase (hashed) + unlock token (TTL) + `pulse_require_unlock()` + `pulse_audit()`
- [x] `auth-api.php` — status / set / unlock / lock (+ brute-force delay)
- [x] Gate + audit on `sites-api.php`, `ssh-api.php`, `sync-api.php` (also added missing Origin+CSRF to sync-api)
- [x] `assets/js/auth.js` — `window.pulsePost` + set/unlock modal + topbar lock button
- [x] Retrofit privileged callers to `pulsePost`: sites-admin.js, ssh.js, dashboard.js, sync.js, migrations.js
- [x] `render.php` — load auth.js, topbar lock button
- [x] Hardening — Host-header check + CSP (nonce on inline scripts)
- [x] `.gitignore` — `.config/.auth`, `.config/.unlock`, `.log/`
- [x] Verify — guards reject without unlock; unlock flow works; lint green

## Phase 1 — Local-dev ergonomics  ✅ DONE (verified)
- [x] Mail catcher — `bin/catch-mail.php`, `lib/phpdev.php` (php.ini set/get/unset), `dev-api.php`, Mail tab + `mail.js`. Verified: real `mail()` captured, listed, disabled+restored.
- [x] Xdebug toggle + php.ini editor — System-tab "PHP tools": Xdebug detect/toggle, php.ini editor with `php -v` validate + auto-revert. Verified revert on a breaking change.
- [x] Per-site .env editor — `sx_env_read/save` + sites-api `env_*`, drawer "Edit .env" → `env.js` (structured key/value, secret masking, comment-preserving save, seed from .env.example). Verified: read, traversal guard, comment preservation.

## Phase 2 — Local-dev power tools  ✅ DONE (verified)
- [x] Ctrl-K command palette — `palette.js` (fuzzy jump to sites/tabs/actions); dashboard.js exposes `pulseOpenDrawer`/`pulseSiteList`.
- [x] Git panel — `sx_git_status` (read-only, `safe.directory=*` for SYSTEM-vs-owner) + sites-api `git_status`, drawer "Git" → `git.js` + copy pull/push. Verified on a real repo.
- [x] Task runner — `pd_task_*` (whitelisted composer/npm/artisan, detached `call`-spawn → polled log) + dev-api `task_*`, drawer "Tasks" → `tasks.js` streaming. Verified: npm task spawned, streamed, exit 0.

## Phase 3 — Live-server connection  ← in progress (CONFIG-DRIVEN, no hardcoded domains/hosts)
- [x] Production health + SSL watch — "Live" tab; `lib/live.php` (monitors config + `live_check`: HTTP status/time + peer-cert expiry) + `live-api.php` + `live.js`. User-managed endpoints in `.config/monitors.json` (gitignored, `.example` committed). SSRF-guarded. Verified: real check → up/200/85ms/cert-56d.
- [ ] Servers config (ssh_host + log paths + allowed commands) — foundation for the next two
- [ ] Remote log tail over SSH (read-only)
- [ ] Remote command runner (per-server allow-list, over SSH)
- [ ] Deploy panel (git state local/staging/main + copy promote-commands; optional webhook POST)

## Phase 3 — Live-server connection
- [ ] Prod health + SSL watch · remote log tail · remote runner · deploy panel
- [ ] (needs input: live domains; which repo/remote drives the Plesk webhook)

## Hardening / follow-ups  ✅ DONE (verified)
- [x] Notification persistence — root cause was the dashboard running over `http://localhost`
      (browsers don't durably keep notification permission there). render.php now **requires
      HTTPS**: cert trusted → 302 to https; cert untrusted → blocking `http-gate.php` that
      trusts the localhost cert (via the normal auth gate) then upgrades to https. No dashboard
      served over http. Verified both branches.
- [x] Notify bell hint — small below-topbar toast guiding the user to the browser's quiet
      address-bar prompt / blocked-permission recovery (dashboard.js `notifyHint`).
- [x] FontAwesome 6.7.2 SVG-JS → **FA7 Pro webfont** (all.min.css + woff2, copied from nebulingo).
      Removes the flaky 2 MB runtime SVG converter; icons render via CSS `::before`. All 54 used
      icons verified present in FA7. render.php/http-gate.php now `<link>` the CSS; sw.js shell
      bumped to dash-v4. Verified live: topbar icons render at 19px in "Font Awesome 7 Pro".
- [x] Drawer **"Open folder"** — opens the site docroot in Explorer via a new `pulsefolder://`
      handler (`bin/pulse-open.ps1`), mirroring the `pulsessh://` terminal button (crosses
      session-0). Wrapper validates the target is an existing directory **under htdocs** (root
      baked into the registered command). First click registers the handler (auth-gated
      `open_folder_enable`), then opens; state embedded as `window.__FOLDER_LAUNCH__`; copies the
      path as a fallback. Verified: rejects System32 / nonexistent / `..` traversal, opens a valid
      folder, button renders with correct path. Replaced the redundant "Copy path" drawer button.
- [x] No console flash — both launchers now register as `wscript.exe "pulse-hidden.vbs" "<wrapper>" …`
      (a windowless host that starts PowerShell hidden), instead of `powershell -WindowStyle Hidden`
      which flashed a conhost window. Status checks require the `pulse-hidden.vbs` marker, so an
      existing pre-hidden registration re-registers itself on next enable/open.
- [x] DB tab — **full "as-is" copy, source → local** (`clone_database` in lib/dbsync.php + `full_copy`
      action + compare-result button). mysqldump source read-only → back up local → drop/recreate →
      import = exact mirror (drops local-only tables). Guards: local-only target, production denied,
      typed-name confirm; creds via env, never stored. Verified end-to-end on throwaway local DBs
      (mirror correct, local-only dropped, source untouched, guards reject). Banner corrected.
- [x] Cross-version import — a newer source's collations (MariaDB `_uca1400_`, MySQL-8 `_0900_`)
      failed on older local MariaDB. On an "Unknown collation/charset" error the dump is rewritten
      (`→ _unicode_ci`, `utf8mb3 → utf8`, DDL lines only) and re-imported. Verified it reproduces &
      fixes the real 1273 error with row data preserved; reported to the user via a `compat` note.
- [x] Service-worker staleness fix — static assets ship with no `Cache-Control`, so the browser
      heuristically cached them and even the network-first SW (which used `fetch(e.request)`)
      served stale JS/CSS, forcing a hard refresh after every change. SW now fetches with
      `{cache:'no-cache'}` (revalidate) and is bumped to dash-v5. Edits now land on a normal reload.
