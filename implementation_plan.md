# XAMPP Pulse — Local ↔ Live Expansion — Implementation Plan

Status: **awaiting approval**. No code is written until this is signed off.

## Principles (reuse what already works)
- Every state‑changing endpoint keeps the existing guards: **localhost‑only + Origin check + CSRF token**, running as SYSTEM via Apache.
- **Forward‑slash paths** in any Apache‑PHP → process call; strip `AP_*` env vars when spawning; detached Apache restarts.
- Resilient JSON (`pulse_json`), network‑first service worker, lint + unused‑CSS gates green before "done".
- New privileged writes always **back up first** (as sites/cert/ssh already do).
- Nothing hardcodes a user path (shared repo) — resolve dynamically like the SSH/`.ssh` resolver.

## Phasing (security first, then safe local wins, then remote power)

### Phase 0 — Security foundation (do first; everything after adds power)
1. **Auth gate.** A passphrase set on first run, stored as a hash in `.config/` (never plaintext). Privileged endpoints require an unlock token (kept in `sessionStorage`, not embedded in the page like the CSRF token). Read‑only monitoring stays open on localhost.
2. **Audit log.** Append‑only `.log/audit.log`: timestamp, action, params (secrets redacted), result. One helper called by every privileged endpoint.
3. **CSP header** on the dashboard + a `Host`‑header check as defence‑in‑depth.
- *Files:* `lib/auth.php`, edits to every `*-api.php`, `render.php`, small JS unlock modal.
- *Why first:* today any local process can scrape `__PULSE_TOKEN__` and drive SYSTEM (and soon your live servers). This closes user→SYSTEM escalation before we add remote reach.

### Phase 1 — Local‑dev ergonomics (independent, high daily value, low risk)
4. **Mail catcher.** Point `php.ini` `sendmail_path` at a bundled PHP catcher that writes each outgoing mail to `.config/mail/*.eml`; a dashboard "Mail" tab shows the inbox (from/to/subject/body/attachments). Dependency‑free, portable. Backs up `php.ini`, Apache restart.
5. **Xdebug + PHP controls.** One‑click enable/disable Xdebug, toggle common extensions, and a guarded `php.ini` editor (backup + `php -i` sanity + restart). PHP‑version switch only if multiple versions are present (auto‑detected).
6. **Per‑site `.env` editor.** Structured key/value editor with secret masking + reveal, backup on save. Reuses the SSH‑tab UI patterns.

### Phase 2 — Local‑dev power tools
7. **Per‑site task runner.** Curated buttons (composer install/update, npm run, `artisan …`) with streamed output. Detected from the site's stack (already collected).
8. **Per‑site git panel.** Branch, ahead/behind, dirty files, recent commits; pull/push/fetch. Read‑mostly, write actions confirmed + audited.
9. **Command palette (Ctrl‑K).** Fuzzy jump to any site / SSH host / action across tabs.

### Phase 3 — Live‑server connection (fully CONFIG-DRIVEN — the tool is shared)
Nothing is hardcoded to any one person's domains/hosts. A new **"Live" tab** carries a
**config UI** whose data lives secret-free in `.config/` (gitignored per-install, with a
committed `.example`). Each user defines their own:
- **Monitored endpoints** — `{label, url}` for prod health + SSL-expiry.
- **Servers** — `{label, ssh_host, log_paths[], allowed_commands[]}` (ssh_host = an alias from their own ~/.ssh/config) for remote log tail + runner.
- **Deploy targets** — `{site/repo, staging_branch, main_branch, webhook_url?}`.

10. **Production health + SSL watch.** Ping each configured endpoint (uptime, response time, **cert-expiry countdown** via the peer cert). Config-managed list.
11. **Remote log tail over SSH.** `ssh <configured host> tail -n … <configured path>` into a pane. Read-only. (Runs `ssh` as SYSTEM — needs a key usable by the service, or falls back to copy-command.)
12. **Remote command runner.** Per-server **allow-list** (from config) of maintenance commands over SSH, with confirm + audit.
13. **Deploy panel.** Git state across local/staging/main + ahead/behind. Because SYSTEM can't hold the user's git push credentials, "Promote" is **copy-ready `merge staging → push main` commands** by default; if a `webhook_url` is configured, also offer a direct **POST to that webhook**.

Reality noted from Phase 2: git/ssh run as SYSTEM, so anything needing the user's remote
credentials (push, authenticated ssh) is surfaced as copy-commands unless a credential-free
path (webhook URL, service-usable key) is configured.

## Open decisions (needed before the relevant phase)
- **Auth gate (Phase 0):** passphrase‑unlock (recommended) vs Windows‑account vs none‑yet?
- **Mail catcher (Phase 1):** OK to repoint `php.ini` `sendmail_path` at the bundled catcher (recommended) vs run MailHog vs skip?
- **PHP versions (Phase 1):** do you keep more than one PHP under XAMPP, or just the one?
- **Live domains (Phase 3):** which production hostnames to monitor (astole.me + …)? Or derive from the SSH hosts?
- **Deploy (Phase 3):** which local repo(s) drive the Plesk webhook — is each `htdocs/<site>` its own git repo, or one repo? And should "Promote" do `git merge staging → push main` from the dashboard (recommended) or hit the webhook URL directly?

## Verification (per feature)
Syntax (`php -l`, `node --check`) · guard tests (no‑CSRF/locked → rejected) · the destructive path tested safely (backup/rollback, non‑disruptive) · `lint_rules.py` + `unused_css_detector.py` green · live page 200. Progress tracked in `task.md`; a `walkthrough.md` at the end.
