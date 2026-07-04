<?php
declare(strict_types=1);

/**
 * PHP-dev tooling: php.ini directive editing + the local mail catcher.
 * Assumes lib/sites.php is already loaded (uses SX_XAMPP, SX_HTDOCS, sx_backup,
 * sx_restart_async, sx_log, SiteError).
 */

define('PD_PHP_INI', SX_XAMPP . '/php/php.ini');
define('PD_MAIL_DIR', SX_HTDOCS . '/xampp-pulse/.config/mail');
define('PD_CATCHER', SX_HTDOCS . '/xampp-pulse/bin/catch-mail.php');

/** Effective value of a php.ini directive (last uncommented occurrence), or null. */
function pd_ini_get(string $key): ?string
{
    $content = (string) @file_get_contents(PD_PHP_INI);
    if (preg_match_all('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*)$/mi', $content, $m)) {
        return trim((string) end($m[1]));
    }
    return null;
}

/** Set (uncomment/replace the first occurrence, else append) a php.ini directive; backs up first. */
function pd_ini_set(string $key, string $value, array &$log): void
{
    $content = (string) @file_get_contents(PD_PHP_INI);
    if ($content === '') {
        throw new SiteError('Cannot read php.ini.');
    }
    $line = $key . ' = ' . $value;
    $pattern = '/^[;\s]*' . preg_quote($key, '/') . '\s*=.*$/mi';
    $content = preg_match($pattern, $content)
        ? (string) preg_replace_callback($pattern, static fn() => $line, $content, 1)
        : $content . "\n" . $line . "\n";
    sx_backup(PD_PHP_INI);
    if (@file_put_contents(PD_PHP_INI, $content) === false) {
        throw new SiteError('Cannot write php.ini (permission denied).');
    }
    sx_log($log, 'ok', 'php.ini: ' . $line);
}

/** Comment out every uncommented occurrence of a php.ini directive; backs up first. */
function pd_ini_unset(string $key, array &$log): void
{
    $content = (string) @file_get_contents(PD_PHP_INI);
    $new = (string) preg_replace_callback(
        '/^(\s*)(' . preg_quote($key, '/') . '\s*=.*)$/mi',
        static fn($m) => $m[1] . ';' . $m[2],
        $content
    );
    if ($new !== $content) {
        sx_backup(PD_PHP_INI);
        @file_put_contents(PD_PHP_INI, $new);
    }
    sx_log($log, 'ok', 'php.ini: ' . $key . ' disabled');
}

/** The sendmail_path command that routes mail() to our catcher. */
function pd_mail_command(): string
{
    $php = str_replace('/', '\\', SX_XAMPP . '/php/php.exe');
    $catcher = str_replace('/', '\\', PD_CATCHER);
    if (strpos($php, ' ') !== false || strpos($catcher, ' ') !== false) {
        return '"\"' . $php . '\" \"' . $catcher . '\""';
    }
    return $php . ' ' . $catcher;
}

function pd_mail_is_on(): bool
{
    $v = pd_ini_get('sendmail_path');
    return $v !== null && stripos($v, 'catch-mail.php') !== false;
}

function pd_mail_enable(): array
{
    $log = [];
    if (!is_dir(PD_MAIL_DIR)) {
        @mkdir(PD_MAIL_DIR, 0777, true);
    }
    pd_ini_set('sendmail_path', pd_mail_command(), $log);
    sx_restart_async();
    sx_log($log, 'ok', 'Restarting Apache…');
    return ['ok' => true, 'on' => true, 'log' => $log, 'message' => 'Mail catching enabled — outgoing mail is captured, never sent.'];
}

function pd_mail_disable(): array
{
    $log = [];
    pd_ini_unset('sendmail_path', $log);
    sx_restart_async();
    sx_log($log, 'ok', 'Restarting Apache…');
    return ['ok' => true, 'on' => false, 'log' => $log, 'message' => 'Mail catching disabled.'];
}

/** id (basename) → safe .eml path; guards traversal. */
function pd_mail_file(string $id): string
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
        throw new SiteError('Bad mail id.');
    }
    $f = PD_MAIL_DIR . '/' . $id . '.eml';
    if (!is_file($f)) {
        throw new SiteError('Mail not found.');
    }
    return $f;
}

function pd_parse_headers(string $raw): array
{
    $head = (string) (preg_split("/\r?\n\r?\n/", $raw, 2)[0] ?? '');
    $h = [];
    foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
        if (preg_match('/^([A-Za-z][A-Za-z-]*):\s*(.*)$/', $line, $m)) {
            $h[strtolower($m[1])] = $m[2];
        }
    }
    return $h;
}

function pd_mail_list(): array
{
    $out = [];
    foreach (glob(PD_MAIL_DIR . '/*.eml') ?: [] as $f) {
        $h = pd_parse_headers((string) @file_get_contents($f));
        $out[] = [
            'id'      => basename($f, '.eml'),
            'from'    => $h['from'] ?? '',
            'to'      => $h['to'] ?? '',
            'subject' => $h['subject'] ?? '(no subject)',
            'date'    => (int) @filemtime($f),
            'size'    => (int) @filesize($f),
        ];
    }
    usort($out, static fn($a, $b) => $b['date'] <=> $a['date']);
    return $out;
}

function pd_mail_read(string $id): array
{
    $raw = (string) @file_get_contents(pd_mail_file($id));
    $body = (string) (preg_split("/\r?\n\r?\n/", $raw, 2)[1] ?? '');
    return ['ok' => true, 'headers' => pd_parse_headers($raw), 'body' => $body, 'raw' => $raw];
}

function pd_mail_delete(string $id): array
{
    @unlink(pd_mail_file($id));
    return ['ok' => true];
}

function pd_mail_clear(): array
{
    foreach (glob(PD_MAIL_DIR . '/*.eml') ?: [] as $f) {
        @unlink($f);
    }
    return ['ok' => true];
}

function pd_mail_count(): int
{
    return count(glob(PD_MAIL_DIR . '/*.eml') ?: []);
}

/* ---------- php.ini editor ---------- */

function pd_ini_read(): string
{
    return (string) @file_get_contents(PD_PHP_INI);
}

/** Save php.ini: backup, write, validate with `php -v`, auto-revert on breakage, restart. */
function pd_ini_save(string $content): array
{
    $log = [];
    if (trim($content) === '') {
        throw new SiteError('Refusing to write an empty php.ini.');
    }
    $content = str_replace("\r\n", "\n", $content);
    $bak = sx_backup(PD_PHP_INI);
    if (@file_put_contents(PD_PHP_INI, $content) === false) {
        throw new SiteError('Cannot write php.ini (permission denied).');
    }
    $r = sx_run([SX_XAMPP . '/php/php.exe', '-v']);
    $broken = $r['code'] !== 0
        || stripos($r['out'], 'Unable to load dynamic library') !== false
        || preg_match('/\bFatal\b/i', $r['out']);
    if ($broken) {
        if ($bak !== null) {
            @copy($bak, PD_PHP_INI);
        }
        throw new SiteError('That php.ini would break PHP — reverted. ' . trim((string) mb_substr($r['out'], 0, 200)));
    }
    sx_log($log, 'ok', 'Saved php.ini (' . strlen($content) . ' bytes), validated with php -v');
    sx_restart_async();
    sx_log($log, 'ok', 'Restarting Apache…');
    return ['ok' => true, 'log' => $log, 'message' => 'php.ini saved — Apache is restarting.'];
}

/* ---------- Xdebug ---------- */

function pd_xdebug_dll(): ?string
{
    foreach (glob(SX_XAMPP . '/php/ext/php_xdebug*.dll') ?: [] as $f) {
        return str_replace('/', '\\', $f);
    }
    return null;
}

function pd_xdebug_status(): array
{
    $dll = pd_xdebug_dll();
    $ze = pd_ini_get('zend_extension');
    $loaded = $ze !== null && stripos($ze, 'xdebug') !== false;
    $mode = pd_ini_get('xdebug.mode');
    return [
        'installed' => $dll !== null,
        'on'        => $loaded && strtolower((string) ($mode ?? '')) !== 'off',
        'mode'      => (string) ($mode ?? ''),
    ];
}

/** Uncomment an existing xdebug zend_extension line, or append one (never touches opcache's). */
function pd_ensure_xdebug_zend(string $dll, array &$log): void
{
    $content = (string) @file_get_contents(PD_PHP_INI);
    if (preg_match('/^\s*zend_extension\s*=.*xdebug.*$/mi', $content)) {
        return;
    }
    if (preg_match('/^;\s*zend_extension\s*=.*xdebug.*$/mi', $content)) {
        $content = (string) preg_replace('/^;\s*zend_extension\s*=.*xdebug.*$/mi', 'zend_extension = ' . $dll, $content, 1);
    } else {
        $content .= "\n[XDebug]\nzend_extension = " . $dll . "\n";
    }
    sx_backup(PD_PHP_INI);
    @file_put_contents(PD_PHP_INI, $content);
    sx_log($log, 'ok', 'php.ini: xdebug zend_extension enabled');
}

function pd_xdebug_enable(): array
{
    $log = [];
    $dll = pd_xdebug_dll();
    if ($dll === null) {
        throw new SiteError('Xdebug is not installed (no php_xdebug.dll in php/ext).');
    }
    pd_ensure_xdebug_zend($dll, $log);
    pd_ini_set('xdebug.mode', 'develop,debug', $log);
    pd_ini_set('xdebug.start_with_request', 'yes', $log);
    sx_restart_async();
    sx_log($log, 'ok', 'Restarting Apache…');
    return ['ok' => true, 'on' => true, 'log' => $log, 'message' => 'Xdebug enabled.'];
}

function pd_xdebug_disable(): array
{
    $log = [];
    // Turn Xdebug off without unloading it (keeps opcache's zend_extension untouched).
    pd_ini_set('xdebug.mode', 'off', $log);
    sx_restart_async();
    sx_log($log, 'ok', 'Restarting Apache…');
    return ['ok' => true, 'on' => false, 'log' => $log, 'message' => 'Xdebug disabled.'];
}

/* ---------- task runner (composer / npm / artisan) ---------- */

define('PD_TASK_DIR', SX_HTDOCS . '/xampp-pulse/.config/tasks');
const PD_ARTISAN = ['migrate', 'migrate:status', 'migrate:fresh', 'db:seed', 'cache:clear', 'config:clear', 'route:clear', 'view:clear', 'optimize:clear', 'route:list', 'about'];

function pd_project_root(string $folder): string
{
    $folder = basename($folder);
    $root = SX_HTDOCS . '/' . $folder;
    if ($folder === '' || !is_dir($root)) {
        throw new SiteError('Unknown site folder.');
    }
    return $root;
}
function pd_php_exe(): string
{
    return '"' . str_replace('/', '\\', SX_XAMPP . '/php/php.exe') . '"';
}
function pd_composer_cmd(string $root): ?string
{
    if (is_file($root . '/composer.phar')) {
        return pd_php_exe() . ' composer.phar';
    }
    $w = sx_run(['where', 'composer']);
    return ($w['code'] === 0 && trim($w['out']) !== '') ? 'composer' : null;
}
function pd_npm_available(): bool
{
    $w = sx_run(['where', 'npm']);
    return $w['code'] === 0 && trim($w['out']) !== '';
}
function pd_npm_scripts(string $root): array
{
    $j = @json_decode((string) @file_get_contents($root . '/package.json'), true);
    $s = (is_array($j) && isset($j['scripts']) && is_array($j['scripts'])) ? array_keys($j['scripts']) : [];
    return array_values(array_filter($s, static fn($k) => is_string($k) && preg_match('/^[A-Za-z0-9:_.-]+$/', $k)));
}

/** Detect the runnable tasks for a site. */
function pd_task_list(string $folder): array
{
    $root = pd_project_root($folder);
    $tasks = [];
    if (is_file($root . '/composer.json') && pd_composer_cmd($root) !== null) {
        foreach (['install' => 'install', 'update' => 'update', 'dump' => 'dump-autoload'] as $k => $sub) {
            $tasks[] = ['key' => 'composer:' . $k, 'label' => 'composer ' . $sub, 'group' => 'Composer'];
        }
    }
    if (is_file($root . '/package.json') && pd_npm_available()) {
        $tasks[] = ['key' => 'npm:install', 'label' => 'npm install', 'group' => 'npm'];
        foreach (pd_npm_scripts($root) as $s) {
            $tasks[] = ['key' => 'npm:run:' . $s, 'label' => 'npm run ' . $s, 'group' => 'npm'];
        }
    }
    if (is_file($root . '/artisan')) {
        foreach (PD_ARTISAN as $c) {
            $tasks[] = ['key' => 'artisan:' . $c, 'label' => 'php artisan ' . $c, 'group' => 'Artisan'];
        }
    }
    return $tasks;
}

/** Map a task key to its (whitelisted) command, or null if not available. */
function pd_task_command(string $root, string $key): ?string
{
    if (in_array($key, ['composer:install', 'composer:update', 'composer:dump'], true)) {
        $c = pd_composer_cmd($root);
        return $c === null ? null : $c . ' ' . ['composer:install' => 'install', 'composer:update' => 'update', 'composer:dump' => 'dump-autoload'][$key];
    }
    if ($key === 'npm:install') {
        return pd_npm_available() ? 'npm install' : null;
    }
    if (strpos($key, 'npm:run:') === 0) {
        $s = substr($key, 8);
        return (pd_npm_available() && in_array($s, pd_npm_scripts($root), true)) ? 'npm run ' . $s : null;
    }
    if (strpos($key, 'artisan:') === 0) {
        $sub = substr($key, 8);
        return (is_file($root . '/artisan') && in_array($sub, PD_ARTISAN, true)) ? pd_php_exe() . ' artisan ' . $sub : null;
    }
    return null;
}

/** Spawn a task detached; output → <id>.log, exit code → <id>.done. Returns the id. */
function pd_task_start(string $folder, string $key): array
{
    $root = pd_project_root($folder);
    $cmd = pd_task_command($root, $key);
    if ($cmd === null) {
        throw new SiteError('That task isn’t available for this site.');
    }
    if (!is_dir(PD_TASK_DIR)) {
        @mkdir(PD_TASK_DIR, 0777, true);
    }
    foreach (glob(PD_TASK_DIR . '/*') ?: [] as $f) {
        if (@filemtime($f) < time() - 86400) {
            @unlink($f);
        }
    }
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $bat = PD_TASK_DIR . '/' . $id . '.bat';
    $logWin = str_replace('/', '\\', PD_TASK_DIR . '/' . $id . '.log');
    $doneWin = str_replace('/', '\\', PD_TASK_DIR . '/' . $id . '.done');
    $rootWin = str_replace('/', '\\', $root);
    // `call` is required so control returns after npm/composer (they are .cmd batch files).
    $content = "@echo off\r\n"
        . "cd /d \"$rootWin\"\r\n"
        . "call $cmd > \"$logWin\" 2>&1\r\n"
        . "echo %ERRORLEVEL% > \"$doneWin\"\r\n"
        . "del \"%~f0\"\r\n";
    @file_put_contents($bat, $content);
    $ph = @popen('start "" /B "' . $bat . '"', 'r');
    if (is_resource($ph)) {
        pclose($ph);
    }
    return ['ok' => true, 'id' => $id, 'task' => $key, 'message' => 'Started.'];
}

function pd_task_poll(string $id): array
{
    if (!preg_match('/^[A-Za-z0-9-]+$/', $id)) {
        throw new SiteError('Bad task id.');
    }
    $log = PD_TASK_DIR . '/' . $id . '.log';
    $done = PD_TASK_DIR . '/' . $id . '.done';
    $output = is_file($log) ? (string) @file_get_contents($log) : '';
    $running = !is_file($done);
    return ['ok' => true, 'id' => $id, 'output' => $output, 'running' => $running, 'code' => $running ? null : (int) trim((string) @file_get_contents($done))];
}
