# xampp-pulse

Localhost-only XAMPP monitoring/dev dashboard (PWA). This is a **local tool** — it runs on the developer's machine, is never deployed to the VPS, and its whole job is to shell out to system tools and probe local sites. Branch: `main`.

## Architecture
- `render.php` — server-rendered shell (`index.php` is a thin require). JSON-polling frontend.
- Per-concern API endpoints at root (`api.php`, `sites-api.php`, `ssh-api.php`, `sync-api.php`, `dev-api.php`, `live-api.php`, `auth-api.php`); logic in `lib/`.
- `bin/` — PowerShell/VBScript launchers for privileged/session-0 Windows actions; `catch-mail.php` mail catcher.
- `.config/` — git-ignored runtime state (`.auth`, `.token`, `ssh.json`, ...) with committed `.example` siblings.

## Security model (keep this gate on every new endpoint, in order)
1. Reject non-`127.0.0.1`/`::1` remotes → 2. reject cross-origin POST → 3. CSRF via `hash_equals(pulse_csrf_token(), ...)` → 4. `pulse_require_unlock()` (TTL token) → 5. `pulse_audit()` before acting → dispatch in `try/catch (Throwable)` returning `pulse_json()`.

## Scanner findings that are BY DESIGN — do not "fix"
- **DANGEROUS_FUNC (`exec`/`proc_open` in `lib/`)**: shelling out to `reg query`, git, php, etc. *is the product*. New call sites must build commands with `escapeshellarg()` and stay behind the endpoint gate above.
- **INSECURE_TLS (`CURLOPT_SSL_VERIFYPEER => false` in `lib/collectors.php`, `lib/live.php`)**: health probes against local vhosts/dev sites with self-signed certs. Intentional *here*; never copy this pattern into deployed projects.
- **SQL_INJECTION in `lib/dbsync.php`**: schema names pass through `mysqli_real_escape_string` (`$esc`) before interpolation into `information_schema` queries. Acceptable for this local tool; prefer prepared statements for any new query.
- `document.write`/`XSS_DOM` hits in the dashboard JS render locally-sourced monitoring data; verify with `esc()` before dismissing a new one.

## Conventions
Strictest PHP in the portfolio: `declare(strict_types=1);` in every file, typed signatures, `esc()` for output, `pulse_json()` (JSON_HEX_TAG + invalid-UTF8-substitute) when JSON is inlined near HTML. JS: `'use strict'` IIFE per file, `window.pulsePost(url, body)` as the only privileged fetch path. CSS: `assets/css/dashboard.css`, light-default with `[data-theme="dark"]` override block.

## Verify
`python ~/.claude/scripts/audit_all.py --changed` from this directory before finishing any task.
