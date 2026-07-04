<?php
declare(strict_types=1);

/**
 * Site management — PHP port of site-manager.exe, runnable because Apache runs
 * as LocalSystem (so PHP is SYSTEM and can write the hosts file, trust certs,
 * edit vhosts and restart Apache). All filesystem paths use FORWARD slashes,
 * which is the only form this Apache-PHP context resolves reliably.
 */

require_once __DIR__ . '/paths.php';

$__xr = str_replace('\\', '/', rtrim(XAMPP_ROOT, "\\/"));
define('SX_XAMPP', $__xr);
define('SX_HTDOCS', $__xr . '/htdocs');
define('SX_CERTS', $__xr . '/certs');
define('SX_VHOSTS', $__xr . '/apache/conf/extra/httpd-vhosts.conf');
define('SX_SSL', $__xr . '/apache/conf/extra/httpd-ssl.conf');
define('SX_OPENSSL', $__xr . '/apache/bin/openssl.exe');
define('SX_HTTPD', $__xr . '/apache/bin/httpd.exe');
define('SX_HOSTS', str_replace('\\', '/', getenv('SystemRoot') ?: 'C:/Windows') . '/System32/drivers/etc/hosts');
define('SX_BACKUPS', $__xr . '/site-manager-backups');

const SX_DOMAIN_RE = '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';
const SX_NAME_RE = '/^[A-Za-z0-9._-]+$/';

class SiteError extends RuntimeException
{
}

/* ---------- small helpers ---------- */
function sx_log(array &$log, string $level, string $msg): void
{
    $log[] = ['level' => $level, 'msg' => $msg];
}

/** Run a console tool (no shell, no window in session 0); returns code + output.
 *  Strips Apache's AP_* env vars so a spawned httpd.exe doesn't think it's a child. */
function sx_run(array $cmd): array
{
    $env = getenv();
    foreach (array_keys($env) as $k) {
        if (stripos($k, 'AP_') === 0) {
            unset($env[$k]);
        }
    }
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
    if (!is_resource($p)) {
        return ['code' => -1, 'out' => 'could not start: ' . ($cmd[0] ?? '')];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($p), 'out' => trim($out . "\n" . $err)];
}

function sx_validate_domain(string $domain): string
{
    $domain = strtolower(trim($domain));
    if ($domain === '') {
        throw new SiteError('Please enter a domain.');
    }
    if (!str_ends_with($domain, '.localhost')) {
        throw new SiteError('Domain must end in “.localhost”.');
    }
    if (!preg_match(SX_DOMAIN_RE, $domain)) {
        throw new SiteError("“$domain” is not a valid hostname.");
    }
    return $domain;
}

function sx_validate_name(string $name, string $kind): string
{
    $name = trim($name);
    if (!preg_match(SX_NAME_RE, $name)) {
        throw new SiteError("$kind may only contain letters, numbers, dot, dash, underscore.");
    }
    return $name;
}

function sx_site_exists(string $domain): bool
{
    $conf = (string) @file_get_contents(SX_SSL);
    return (bool) preg_match('/^\s*ServerName\s+' . preg_quote($domain, '/') . '\s*$/mi', $conf);
}

/* ---------- backups ---------- */
function sx_backup(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }
    if (!is_dir(SX_BACKUPS)) {
        @mkdir(SX_BACKUPS, 0777, true);
    }
    $dest = SX_BACKUPS . '/' . basename($path) . '.bak-' . date('Ymd-His');
    @copy($path, $dest);
    foreach (array_slice(array_reverse(glob(SX_BACKUPS . '/' . basename($path) . '.bak-*') ?: []), 5) as $old) {
        @unlink($old);
    }
    return $dest;
}

/**
 * Issue and trust a proper certificate for https://localhost, replacing XAMPP's
 * stock self-signed/expired one in place. Records the trusted fingerprint so the
 * dashboard knows it's fixed, then restarts Apache to serve it.
 */
function sx_fix_localhost_cert(): array
{
    $log = [];
    $crt = SX_XAMPP . '/apache/conf/ssl.crt/server.crt';
    $key = SX_XAMPP . '/apache/conf/ssl.key/server.key';
    $cnf = SX_CERTS . '/localhost.cnf';
    if (!is_dir(SX_CERTS)) {
        @mkdir(SX_CERTS, 0777, true);
    }
    foreach ([$crt, $key] as $f) {
        if (is_file($f)) {
            $b = sx_backup($f);
            if ($b !== null) {
                sx_log($log, 'ok', 'Backed up ' . basename($f) . ' → ' . basename($b));
            }
        }
    }
    @file_put_contents($cnf,
        "[req]\ndefault_bits=2048\nprompt=no\ndefault_md=sha256\nreq_extensions=req_ext\nx509_extensions=v3_req\ndistinguished_name=dn\n\n"
        . "[dn]\nC=US\nST=Local\nL=LocalHost\nO=XAMPP Dev\nCN=localhost\n\n"
        . "[req_ext]\nsubjectAltName=@alt\n\n[v3_req]\nsubjectAltName=@alt\n\n[alt]\nDNS.1=localhost\nIP.1=127.0.0.1\nIP.2=::1\n");
    $r = sx_run([SX_OPENSSL, 'req', '-new', '-x509', '-newkey', 'rsa:2048', '-sha256', '-nodes', '-keyout', $key, '-days', '825', '-out', $crt, '-config', $cnf]);
    @unlink($cnf);
    if ($r['code'] !== 0) {
        throw new SiteError('OpenSSL failed: ' . $r['out']);
    }
    sx_log($log, 'ok', 'Generated a localhost certificate (SAN: localhost, 127.0.0.1, ::1)');

    $t = sx_run(['certutil', '-addstore', '-f', 'Root', $crt]);
    sx_log($log, $t['code'] === 0 ? 'ok' : 'warn',
        $t['code'] === 0 ? 'Trusted it in the Windows Root store' : 'Could not trust it (certutil failed) — trust it manually');

    $fp = (string) openssl_x509_fingerprint((string) @file_get_contents($crt), 'sha256');
    $cfgDir = SX_HTDOCS . '/xampp-pulse/.config';
    if (!is_dir($cfgDir)) {
        @mkdir($cfgDir, 0777, true);
    }
    @file_put_contents($cfgDir . '/localhost-cert.json', json_encode(['sha256' => $fp, 'at' => date('c')]));

    sx_restart_async();
    sx_log($log, 'ok', 'Restarting Apache to serve the new certificate…');
    return ['ok' => true, 'log' => $log, 'message' => 'localhost is now on a trusted certificate.'];
}

/** Absolute path to the pulsessh:// launcher wrapper (shipped in the repo). */
function sx_ssh_wrapper(): string
{
    return SX_HTDOCS . '/xampp-pulse/bin/pulse-ssh.ps1';
}

/** Whether the pulsessh:// protocol handler is registered and points at our wrapper. */
function sx_ssh_protocol_status(): bool
{
    $r = sx_run(['reg', 'query', 'HKLM\\SOFTWARE\\Classes\\pulsessh\\shell\\open\\command', '/ve']);
    return $r['code'] === 0 && stripos($r['out'], 'pulse-ssh.ps1') !== false;
}

/** Register the pulsessh:// handler machine-wide (needs the SYSTEM context). */
function sx_register_ssh_protocol(): array
{
    $log = [];
    $wrapper = sx_ssh_wrapper();
    if (!is_file($wrapper)) {
        throw new SiteError('Launcher script missing: ' . $wrapper);
    }
    $cmd = 'powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "' . $wrapper . '" "%1"';
    $key = 'HKLM\\SOFTWARE\\Classes\\pulsessh';
    $r1 = sx_run(['reg', 'add', $key, '/ve', '/d', 'URL:Pulse SSH', '/f']);
    $r2 = sx_run(['reg', 'add', $key, '/v', 'URL Protocol', '/f']);
    $r3 = sx_run(['reg', 'add', $key . '\\shell\\open\\command', '/ve', '/d', $cmd, '/f']);
    if ($r1['code'] !== 0 || $r2['code'] !== 0 || $r3['code'] !== 0) {
        throw new SiteError('Registry write failed: ' . trim($r1['out'] . ' ' . $r2['out'] . ' ' . $r3['out']));
    }
    sx_log($log, 'ok', 'Registered pulsessh:// → ' . basename($wrapper));
    return ['ok' => true, 'enabled' => true, 'log' => $log, 'message' => 'Terminal launching enabled.'];
}

/** Remove the pulsessh:// handler (idempotent). */
function sx_unregister_ssh_protocol(): array
{
    $log = [];
    sx_run(['reg', 'delete', 'HKLM\\SOFTWARE\\Classes\\pulsessh', '/f']);
    sx_log($log, 'ok', 'Removed the pulsessh:// handler.');
    return ['ok' => true, 'enabled' => false, 'log' => $log, 'message' => 'Terminal launching disabled.'];
}

/** Find the Windows service whose binary path matches $needle (e.g. 'mysqld'). */
function sx_service_name(string $needle): ?string
{
    $ps = "Get-CimInstance Win32_Service | Where-Object { \$_.PathName -match '$needle' } | Select-Object -First 1 -ExpandProperty Name";
    $r = sx_run(['powershell', '-NoProfile', '-NonInteractive', '-Command', $ps]);
    $name = trim($r['out']);
    return ($r['code'] === 0 && $name !== '' && preg_match('/^[A-Za-z0-9_.\- ]+$/', $name)) ? $name : null;
}

/** Start/stop/restart a monitored service. Apache is restart-only (it hosts us). */
function sx_service_control(string $service, string $op): array
{
    $log = [];
    if (!in_array($op, ['start', 'stop', 'restart'], true)) {
        throw new SiteError('Unknown operation.');
    }
    if ($service === 'apache') {
        if ($op !== 'restart') {
            throw new SiteError('Apache is restart-only from the dashboard — a stop would take the dashboard down.');
        }
        sx_restart_async();
        sx_log($log, 'ok', 'Apache restart signalled (httpd -k restart).');
        return ['ok' => true, 'log' => $log, 'message' => 'Apache is restarting…'];
    }
    if ($service === 'mysql') {
        $name = sx_service_name('mysqld');
        if ($name === null) {
            throw new SiteError('No MySQL/MariaDB Windows service found — it may be running as a process; use the XAMPP control panel.');
        }
        $verb = ['start' => 'Start-Service', 'stop' => 'Stop-Service', 'restart' => 'Restart-Service'][$op];
        $r = sx_run(['powershell', '-NoProfile', '-NonInteractive', '-Command', "$verb -Name '$name' -Force -ErrorAction Stop; 'ok'"]);
        $ok = $r['code'] === 0;
        sx_log($log, $ok ? 'ok' : 'warn', "$verb $name — " . ($ok ? 'ok' : trim($r['out'])));
        return ['ok' => $ok, 'log' => $log, 'message' => 'MySQL ' . $op . ($ok ? ' complete.' : ' failed — check the log.')];
    }
    throw new SiteError('Unknown service.');
}

/** Run git within a project root. safe.directory=* because Apache runs as SYSTEM while
 *  the repos are owned by the interactive user (git's "dubious ownership" guard). */
function sx_git(string $root, array $args): array
{
    return sx_run(array_merge(['git', '-c', 'safe.directory=*', '-C', $root], $args));
}

/** Read-only git status for a site (branch, dirty, ahead/behind, recent commits). */
function sx_git_status(string $folder): array
{
    $folder = basename($folder);
    $root = SX_HTDOCS . '/' . $folder;
    if ($folder === '' || !is_dir($root)) {
        throw new SiteError('Unknown site folder.');
    }
    if (!is_dir($root . '/.git')) {
        return ['ok' => true, 'is_repo' => false, 'folder' => $folder, 'root' => $root];
    }
    $branch = trim(sx_git($root, ['rev-parse', '--abbrev-ref', 'HEAD'])['out']);
    $porcelain = sx_git($root, ['status', '--porcelain'])['out'];
    $dirty = $porcelain === '' ? 0 : count(array_filter(explode("\n", $porcelain), static fn($l) => trim($l) !== ''));
    $remote = trim(sx_git($root, ['remote', 'get-url', 'origin'])['out']);

    $ahead = 0;
    $behind = 0;
    $upstream = '';
    $up = sx_git($root, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
    if ($up['code'] === 0) {
        $upstream = trim($up['out']);
        $counts = trim(sx_git($root, ['rev-list', '--left-right', '--count', 'HEAD...@{u}'])['out']);
        if (preg_match('/^(\d+)\s+(\d+)$/', $counts, $m)) {
            $ahead = (int) $m[1];
            $behind = (int) $m[2];
        }
    }

    $commits = [];
    foreach (explode("\n", sx_git($root, ['log', '-8', '--pretty=format:%h%x1f%s%x1f%an%x1f%ar'])['out']) as $line) {
        if ($line === '') {
            continue;
        }
        $p = explode("\x1f", $line);
        if (count($p) >= 4) {
            $commits[] = ['hash' => $p[0], 'subject' => $p[1], 'author' => $p[2], 'when' => $p[3]];
        }
    }
    return [
        'ok' => true, 'is_repo' => true, 'folder' => $folder, 'root' => $root,
        'branch' => $branch, 'dirty' => $dirty, 'ahead' => $ahead, 'behind' => $behind,
        'upstream' => $upstream, 'remote' => $remote, 'commits' => $commits,
    ];
}

/** Read a site's .env (project root = htdocs/<folder>); seeds from .env.example if absent. */
function sx_env_read(string $folder): array
{
    $folder = basename($folder);
    $root = SX_HTDOCS . '/' . $folder;
    if ($folder === '' || !is_dir($root)) {
        throw new SiteError('Unknown site folder.');
    }
    $path = $root . '/.env';
    $exists = is_file($path);
    $content = $exists ? (string) @file_get_contents($path) : '';
    $seeded = false;
    if (!$exists && is_file($root . '/.env.example')) {
        $content = (string) @file_get_contents($root . '/.env.example');
        $seeded = true;
    }
    return ['ok' => true, 'folder' => $folder, 'path' => $path, 'exists' => $exists, 'seeded' => $seeded, 'content' => $content];
}

/** Write a site's .env, backing up any existing file first. */
function sx_env_save(string $folder, string $content): array
{
    $folder = basename($folder);
    $root = SX_HTDOCS . '/' . $folder;
    if ($folder === '' || !is_dir($root)) {
        throw new SiteError('Unknown site folder.');
    }
    $path = $root . '/.env';
    $log = [];
    $content = str_replace("\r\n", "\n", $content);
    if (is_file($path)) {
        $bak = sx_backup($path);
        if ($bak !== null) {
            sx_log($log, 'ok', 'Backed up existing .env → ' . basename($bak));
        }
    }
    if (@file_put_contents($path, $content) === false) {
        throw new SiteError('Could not write ' . $path . ' (permission denied).');
    }
    sx_log($log, 'ok', 'Saved ' . $path . ' (' . strlen($content) . ' bytes)');
    return ['ok' => true, 'log' => $log, 'message' => '.env saved.'];
}

/** Point htdocs/index.php at the Pulse dashboard, backing up any existing file. */
function sx_fix_root_index(): array
{
    $log = [];
    $target = SX_HTDOCS . '/index.php';
    if (is_file($target)) {
        $bak = sx_backup($target);
        if ($bak !== null) {
            sx_log($log, 'ok', 'Backed up existing index.php → ' . basename($bak));
        }
    }
    $content = "<?php\ndeclare(strict_types=1);\n\n/**\n * htdocs entry point — serves the XAMPP Pulse dashboard.\n * The original XAMPP redirect is preserved in index.default.php.\n */\nrequire __DIR__ . '/xampp-pulse/render.php';\n";
    if (@file_put_contents($target, $content) === false) {
        throw new SiteError('Could not write ' . $target . ' (permission denied).');
    }
    sx_log($log, 'ok', 'Wrote index.php → requires xampp-pulse/render.php');
    return ['ok' => true, 'log' => $log, 'message' => 'localhost now serves XAMPP Pulse.'];
}

function sx_backup_all(): array
{
    return [SX_HOSTS => sx_backup(SX_HOSTS), SX_VHOSTS => sx_backup(SX_VHOSTS), SX_SSL => sx_backup(SX_SSL)];
}
function sx_restore(array $backups, array $paths): void
{
    foreach ($paths as $p) {
        if (!empty($backups[$p]) && is_file($backups[$p])) {
            @copy($backups[$p], $p);
        }
    }
}

/* ---------- docroot ---------- */
function sx_ensure_docroot(string $folder, array &$log): void
{
    $path = SX_HTDOCS . '/' . $folder;
    if (is_dir($path)) {
        sx_log($log, 'skip', "DocumentRoot already exists: htdocs/$folder");
        return;
    }
    @mkdir($path, 0777, true);
    sx_log($log, 'ok', "Created DocumentRoot: htdocs/$folder");
    if (is_dir($path) && count((array) @scandir($path)) <= 2) {
        @file_put_contents($path . '/index.php',
            "<?php declare(strict_types=1);\n\$host = htmlspecialchars(\$_SERVER['HTTP_HOST'] ?? 'local site', ENT_QUOTES, 'UTF-8');\n?>\n"
            . "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>It works</title></head>\n"
            . "<body><h1><?= \$host ?></h1><p>Placeholder created by XAMPP Pulse.</p></body></html>\n");
        sx_log($log, 'ok', 'Added placeholder index.php');
    }
}

/* ---------- certificate ---------- */
function sx_generate_cert(string $domain, array &$log): void
{
    $crt = SX_CERTS . "/$domain.crt";
    $key = SX_CERTS . "/$domain.key";
    $cnf = SX_CERTS . "/$domain.cnf";
    if (!is_dir(SX_CERTS)) {
        @mkdir(SX_CERTS, 0777, true);
    }
    @file_put_contents($cnf,
        "[req]\ndefault_bits=2048\nprompt=no\ndefault_md=sha256\nreq_extensions=req_ext\nx509_extensions=v3_req\ndistinguished_name=dn\n\n"
        . "[dn]\nC=US\nST=Local\nL=LocalHost\nO=XAMPP Dev\nCN=$domain\n\n"
        . "[req_ext]\nsubjectAltName=@alt\n\n[v3_req]\nsubjectAltName=@alt\n\n[alt]\nDNS.1=$domain\n");
    $r = sx_run([SX_OPENSSL, 'req', '-new', '-x509', '-newkey', 'rsa:2048', '-sha256', '-nodes', '-keyout', $key, '-days', '3650', '-out', $crt, '-config', $cnf]);
    @unlink($cnf);
    if ($r['code'] !== 0) {
        throw new SiteError('OpenSSL failed: ' . $r['out']);
    }
    sx_log($log, 'ok', "Generated certificate: $domain.crt");
    $t = sx_run(['certutil', '-addstore', '-f', 'Root', $crt]);
    sx_log($log, $t['code'] === 0 ? 'ok' : 'warn', $t['code'] === 0 ? 'Certificate trusted in Windows Root store' : 'Could not trust certificate (certutil failed)');
}

function sx_untrust_delete_cert(string $domain, array &$log): void
{
    $r = sx_run(['certutil', '-delstore', 'Root', $domain]);
    sx_log($log, $r['code'] === 0 ? 'ok' : 'skip', $r['code'] === 0 ? "Untrusted certificate: $domain" : 'Certificate was not in the Root store');
    $removed = [];
    foreach ([SX_CERTS . "/$domain.crt", SX_CERTS . "/$domain.key"] as $f) {
        if (is_file($f)) {
            @unlink($f);
            $removed[] = basename($f);
        }
    }
    sx_log($log, $removed ? 'ok' : 'skip', $removed ? 'Deleted cert files: ' . implode(', ', $removed) : 'No cert files to delete');
}

/* ---------- hosts ---------- */
function sx_add_hosts(string $domain, array &$log): void
{
    $lines = preg_split('/\r\n|\n/', (string) @file_get_contents(SX_HOSTS));
    foreach ($lines as $ln) {
        if (preg_match('/^\s*127\.0\.0\.1\s+' . preg_quote($domain, '/') . '\s*$/', $ln)) {
            sx_log($log, 'skip', "hosts entry already present: $domain");
            return;
        }
    }
    $insert = null;
    foreach ($lines as $i => $ln) {
        if (preg_match('/^\s*127\.0\.0\.1\s+\S+\.localhost\b/', $ln)) {
            $insert = $i + 1;
        }
    }
    $new = "127.0.0.1   $domain";
    if ($insert === null) {
        $lines[] = $new;
    } else {
        array_splice($lines, $insert, 0, $new);
    }
    if (@file_put_contents(SX_HOSTS, implode("\r\n", array_map('rtrim', $lines)) . "\r\n") === false) {
        throw new SiteError('Could not write the hosts file (is Apache running as the LocalSystem service?).');
    }
    sx_log($log, 'ok', "Added hosts entry: $new");
}

function sx_remove_hosts(string $domain, array &$log): void
{
    $lines = preg_split('/\r\n|\n/', (string) @file_get_contents(SX_HOSTS));
    $keep = array_values(array_filter($lines, static fn($ln) => !preg_match('/^\s*127\.0\.0\.1\s+' . preg_quote($domain, '/') . '\s*$/', $ln)));
    if (count($keep) === count($lines)) {
        sx_log($log, 'skip', "No hosts entry for $domain");
        return;
    }
    @file_put_contents(SX_HOSTS, implode("\r\n", array_map('rtrim', $keep)) . "\r\n");
    sx_log($log, 'ok', "Removed hosts entry: $domain");
}

/* ---------- vhost blocks ---------- */
function sx_vhost80(string $domain, string $folder, string $slug): string
{
    $x = SX_XAMPP;
    return "<VirtualHost *:80>\n    ServerName $domain\n    DocumentRoot \"$x/htdocs/$folder\"\n\n    Redirect permanent / https://$domain/\n\n    ErrorLog \"$x/apache/logs/{$slug}-http-error.log\"\n    CustomLog \"$x/apache/logs/{$slug}-http-access.log\" common\n</VirtualHost>";
}
function sx_vhost443(string $domain, string $folder, string $slug): string
{
    $x = SX_XAMPP;
    return "<VirtualHost *:443>\n    DocumentRoot \"$x/htdocs/$folder\"\n    ServerName $domain\n    ErrorLog \"\${SRVROOT}/logs/{$slug}_error.log\"\n    TransferLog \"\${SRVROOT}/logs/{$slug}_access.log\"\n\n    SSLEngine on\n    SSLCipherSuite ALL:!ADH:!EXPORTSRL:RC4+RSA:+HIGH:+MEDIUM:+LOW:+SSLv2:+EXP\n    SSLHonorCipherOrder on\n\n    SSLCertificateFile \"$x/certs/$domain.crt\"\n    SSLCertificateKeyFile \"$x/certs/$domain.key\"\n\n    <Directory \"$x/htdocs/$folder\">\n        Options Indexes FollowSymLinks MultiViews\n        AllowOverride All\n        Require all granted\n    </Directory>\n</VirtualHost>";
}
function sx_append_block(string $conf, string $block, string $domain, array &$log): void
{
    $content = (string) @file_get_contents($conf);
    if (preg_match('/^\s*ServerName\s+' . preg_quote($domain, '/') . '\s*$/mi', $content)) {
        sx_log($log, 'skip', basename($conf) . " already has a block for $domain");
        return;
    }
    $sep = str_ends_with($content, "\n\n") ? '' : (str_ends_with($content, "\n") ? "\n" : "\n\n");
    @file_put_contents($conf, $content . $sep . "\n" . $block . "\n");
    sx_log($log, 'ok', 'Appended block to ' . basename($conf));
}
function sx_remove_block(string $conf, string $domain, array &$log): void
{
    $content = (string) @file_get_contents($conf);
    $removed = false;
    $new = preg_replace_callback('/[ \t]*<VirtualHost\b.*?<\/VirtualHost>[ \t]*\n?/is', static function ($m) use ($domain, &$removed) {
        if (preg_match('/^\s*ServerName\s+' . preg_quote($domain, '/') . '\s*$/mi', $m[0])) {
            $removed = true;
            return '';
        }
        return $m[0];
    }, $content);
    if (!$removed) {
        sx_log($log, 'skip', basename($conf) . " has no block for $domain");
        return;
    }
    $new = rtrim((string) preg_replace("/\n{3,}/", "\n\n", $new)) . "\n";
    @file_put_contents($conf, $new);
    sx_log($log, 'ok', 'Removed block from ' . basename($conf));
}

/* ---------- validate + (deferred) restart ---------- */
function sx_validate_and_restart(array $backups, array &$log): void
{
    $t = sx_run([SX_HTTPD, '-t']);
    if ($t['code'] !== 0) {
        sx_restore($backups, [SX_VHOSTS, SX_SSL, SX_HOSTS]);
        throw new SiteError('Apache config test FAILED — rolled back. ' . $t['out']);
    }
    sx_log($log, 'ok', 'Apache config test passed (Syntax OK)');
    sx_restart_async();
    sx_log($log, 'ok', 'Apache restarting…');
}

/** Restart Apache from a detached, slightly-delayed process so the HTTP response
 *  is fully delivered first (a hard restart would otherwise drop this connection). */
function sx_restart_async(): void
{
    $httpd = str_replace('/', '\\', SX_HTTPD);
    $bat = (sys_get_temp_dir() ?: 'C:/Windows/Temp') . '/pulse_restart_' . uniqid() . '.bat';
    @file_put_contents($bat, "@echo off\r\nset \"AP_PARENT_PID=\"\r\ntimeout /t 1 /nobreak >nul\r\n\"$httpd\" -k restart\r\ndel \"%~f0\"\r\n");
    @pclose(@popen('start "" /B "' . $bat . '"', 'r'));
}

/* ====================  operations  ==================== */
function sx_create(string $domain, string $folder, string $slug): array
{
    $log = [];
    $domain = sx_validate_domain($domain);
    if (sx_site_exists($domain)) {
        throw new SiteError("A site for $domain already exists.");
    }
    $folder = sx_validate_name($folder !== '' ? $folder : explode('.', $domain)[0], 'Folder');
    $slug = sx_validate_name($slug !== '' ? $slug : $folder, 'Slug');

    sx_ensure_docroot($folder, $log);
    sx_generate_cert($domain, $log);
    $backups = sx_backup_all();
    sx_add_hosts($domain, $log);
    sx_append_block(SX_VHOSTS, sx_vhost80($domain, $folder, $slug), $domain, $log);
    sx_append_block(SX_SSL, sx_vhost443($domain, $folder, $slug), $domain, $log);
    sx_validate_and_restart($backups, $log);
    return ['ok' => true, 'log' => $log, 'message' => "$domain is ready at https://$domain/"];
}

function sx_rename(string $oldDomain, string $newDomain, string $newFolder): array
{
    $log = [];
    $oldDomain = strtolower(trim($oldDomain));
    $newDomain = sx_validate_domain($newDomain);
    if ($newDomain === $oldDomain) {
        throw new SiteError('New domain is the same as the current one.');
    }
    if (sx_site_exists($newDomain)) {
        throw new SiteError("A site for $newDomain already exists.");
    }
    $newFolder = sx_validate_name($newFolder !== '' ? $newFolder : explode('.', $newDomain)[0], 'Folder');
    $newSlug = $newFolder;

    sx_ensure_docroot($newFolder, $log);
    sx_generate_cert($newDomain, $log);
    $backups = sx_backup_all();
    sx_remove_hosts($oldDomain, $log);
    sx_add_hosts($newDomain, $log);
    sx_remove_block(SX_VHOSTS, $oldDomain, $log);
    sx_remove_block(SX_SSL, $oldDomain, $log);
    sx_append_block(SX_VHOSTS, sx_vhost80($newDomain, $newFolder, $newSlug), $newDomain, $log);
    sx_append_block(SX_SSL, sx_vhost443($newDomain, $newFolder, $newSlug), $newDomain, $log);
    sx_validate_and_restart($backups, $log);
    sx_untrust_delete_cert($oldDomain, $log);
    return ['ok' => true, 'log' => $log, 'message' => "Renamed to https://$newDomain/ (old folder kept)"];
}

function sx_remove(string $domain, bool $keepCert): array
{
    $log = [];
    $domain = strtolower(trim($domain));
    if ($domain === '') {
        throw new SiteError('No domain given.');
    }
    $backups = sx_backup_all();
    sx_remove_hosts($domain, $log);
    sx_remove_block(SX_VHOSTS, $domain, $log);
    sx_remove_block(SX_SSL, $domain, $log);
    sx_validate_and_restart($backups, $log);
    if ($keepCert) {
        sx_log($log, 'skip', 'Keeping certificate (kept on request).');
    } else {
        sx_untrust_delete_cert($domain, $log);
    }
    return ['ok' => true, 'log' => $log, 'message' => "Removed $domain. The project folder was left untouched."];
}
