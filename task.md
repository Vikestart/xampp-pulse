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
