<?php
declare(strict_types=1);

/** Escape a value for safe HTML output. */
function esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Resilient JSON encoder for snapshots and API responses.
 *
 *  - INVALID_UTF8_SUBSTITUTE / PARTIAL_OUTPUT_ON_ERROR: collected data (log
 *    tails, file paths, DB metadata) can contain non-UTF-8 bytes, which make a
 *    plain json_encode() return false — producing broken output like `= ;`.
 *    Substitute invalid bytes and never return false.
 *  - HEX_TAG: the snapshot is embedded inline in a <script> on a page that also
 *    carries the privileged CSRF token, so escape < and > to make a `</script>`
 *    break-out (and the XSS → token-theft it would enable) impossible. Harmless
 *    for fetched JSON — the browser parses < back to '<'.
 */
function pulse_json($value): string
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $json = json_encode($value, $flags);
    return $json === false ? 'null' : $json;
}

/** Absolute htdocs path (this file lives at htdocs/xampp-pulse/lib/helpers.php). */
function pulse_htdocs_dir(): string
{
    return dirname(__DIR__, 2);
}

/** Whether htdocs/index.php is wired to serve the Pulse dashboard at the web root. */
function pulse_root_index_ok(): bool
{
    $file = pulse_htdocs_dir() . '/index.php';
    if (!is_file($file)) {
        return false;
    }
    return stripos((string) @file_get_contents($file), 'xampp-pulse/render.php') !== false;
}

/** Persistent per-install CSRF token (for the privileged, state-changing endpoints). */
function pulse_csrf_token(): string
{
    $dir = __DIR__ . '/../.config';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $file = $dir . '/.token';
    if (!is_file($file) || trim((string) @file_get_contents($file)) === '') {
        @file_put_contents($file, bin2hex(random_bytes(32)));
    }
    return trim((string) @file_get_contents($file));
}

/** Human-readable byte size. */
function human_bytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $decimals = ($i === 0 || $bytes >= 100) ? 0 : 1;
    return round($bytes, $decimals) . ' ' . $units[$i];
}

/** Quick TCP port reachability check. */
function port_open(string $host, int $port, float $timeout = 0.5): bool
{
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

/** Read the last $lines lines of a (possibly large) file, tail-style. */
function tail_lines(string $path, int $lines = 10): array
{
    if (!is_file($path)) {
        return [];
    }
    $size = (int) (@filesize($path) ?: 0);
    if ($size === 0) {
        return [];
    }
    $fp = @fopen($path, 'rb');
    if (!$fp) {
        return [];
    }
    $read = min($size, 65536);
    fseek($fp, -$read, SEEK_END);
    $data = (string) fread($fp, $read);
    fclose($fp);
    $all = preg_split('/\r\n|\r|\n/', rtrim($data, "\r\n")) ?: [];
    return array_values(array_filter(array_slice($all, -$lines), static fn($l) => trim($l) !== ''));
}

/** Count error vs warning lines in the tail of a log file. */
function log_issues(string $path): array
{
    $errors = 0;
    $warnings = 0;
    foreach (tail_lines($path, 60) as $line) {
        if (preg_match('/PHP (Fatal error|Parse error)|exception|\[[^\]]*:error\]/i', $line)) {
            $errors++;
        } elseif (preg_match('/PHP (Warning|Deprecated|Notice)|\[[^\]]*:warn\]/i', $line)) {
            $warnings++;
        }
    }
    return ['errors' => $errors, 'warnings' => $warnings];
}

/** Detect a project's stack from marker files. */
function detect_stack(string $docroot): string
{
    if (is_file("$docroot/wp-config.php") || is_file("$docroot/wp-load.php")) {
        return 'WordPress';
    }
    if (is_file("$docroot/artisan")) {
        return 'Laravel';
    }
    if (is_file("$docroot/bin/console") || is_file("$docroot/symfony.lock")) {
        return 'Symfony';
    }
    if (is_file("$docroot/composer.json")) {
        $json = (string) @file_get_contents("$docroot/composer.json");
        if (stripos($json, 'laravel/framework') !== false) {
            return 'Laravel';
        }
        if (stripos($json, 'symfony/') !== false) {
            return 'Symfony';
        }
        return 'Composer';
    }
    if (is_file("$docroot/package.json")) {
        return 'Node';
    }
    if (glob("$docroot/*.php")) {
        return 'PHP';
    }
    if (is_file("$docroot/index.html") || is_file("$docroot/index.htm")) {
        return 'Static';
    }
    return '';
}

/** Writable cache directory for the dashboard. */
function cache_dir(): string
{
    $dir = __DIR__ . '/../.cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

/** Recursive folder size with a file-count cap. */
function project_size(string $docroot, int $cap = 8000): array
{
    if (!is_dir($docroot)) {
        return ['size' => 0.0, 'capped' => false];
    }
    $size = 0.0;
    $count = 0;
    $capped = false;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($docroot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            $size += (float) $file->getSize();
            if (++$count >= $cap) {
                $capped = true;
                break;
            }
        }
    } catch (\Throwable $e) {
        /* unreadable subtree — ignore */
    }
    return ['size' => $size, 'capped' => $capped];
}

/**
 * Cached folder size. Computes at most ONE fresh scan per request so live polling
 * stays fast; other folders return their cached (possibly stale) value, or null
 * if not yet computed.
 */
function project_size_cached(string $docroot, int $ttl = 600): ?array
{
    static $computed = 0;
    $file = cache_dir() . '/sizes.json';
    $cache = is_file($file) ? (array) json_decode((string) @file_get_contents($file), true) : [];
    $key = md5($docroot);
    $fresh = isset($cache[$key]) && (time() - (int) ($cache[$key]['ts'] ?? 0)) < $ttl;

    if ($fresh) {
        return ['size' => (float) $cache[$key]['size'], 'capped' => (bool) $cache[$key]['capped']];
    }
    if ($computed >= 1) {
        return isset($cache[$key])
            ? ['size' => (float) $cache[$key]['size'], 'capped' => (bool) $cache[$key]['capped']]
            : null;
    }
    $computed++;
    $res = project_size($docroot);
    $cache[$key] = ['size' => $res['size'], 'capped' => $res['capped'], 'ts' => time()];
    @file_put_contents($file, json_encode($cache));
    return $res;
}

/** Compact relative time, e.g. "3m ago", "2h ago", "5d ago". */
function rel_time(int $ts): string
{
    $d = time() - $ts;
    if ($d < 60) {
        return 'just now';
    }
    if ($d < 3600) {
        return floor($d / 60) . 'm ago';
    }
    if ($d < 86400) {
        return floor($d / 3600) . 'h ago';
    }
    if ($d < 2592000) {
        return floor($d / 86400) . 'd ago';
    }
    return date('Y-m-d', $ts);
}

/** Certificate expiry info (expiry date + whole days left), or null. */
function cert_info(string $crtPath): ?array
{
    if (!is_file($crtPath) || !function_exists('openssl_x509_parse')) {
        return null;
    }
    $pem = @file_get_contents($crtPath);
    if ($pem === false) {
        return null;
    }
    $cert = @openssl_x509_parse($pem);
    if (!is_array($cert) || empty($cert['validTo_time_t'])) {
        return null;
    }
    $expires = (int) $cert['validTo_time_t'];
    return [
        'expires'   => date('Y-m-d', $expires),
        'days_left' => (int) floor(($expires - time()) / 86400),
    ];
}
