<?php
declare(strict_types=1);

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/helpers.php';

/** Folders under htdocs that are not user projects. */
const DASH_SKIP_FOLDERS = ['dashboard', 'img', 'webalizer', 'xampp', 'xampp-pulse', '__pycache__'];

/**
 * Enumerate local sites from the Apache SSL vhosts, plus any htdocs project
 * folders that are not yet configured as a vhost. Live HTTPS reachability is
 * resolved in parallel for configured sites.
 *
 * @return array<int,array<string,mixed>>
 */
function collect_sites(): array
{
    $conf = (string) @file_get_contents(XAMPP_SSL_CONF);
    $htdocsNorm = strtolower(rtrim(str_replace('\\', '/', XAMPP_HTDOCS), '/'));
    $sites = [];

    if (preg_match_all('/<VirtualHost[^>]*>(.*?)<\/VirtualHost>/is', $conf, $blocks)) {
        foreach ($blocks[1] as $body) {
            if (!preg_match('/^\s*ServerName\s+(\S+)\s*$/mi', $body, $sn)) {
                continue;
            }
            if (!preg_match('/^\s*DocumentRoot\s+"?([^"\n]+?)"?\s*$/mi', $body, $dr)) {
                continue;
            }
            $domain = trim($sn[1]);
            $docroot = rtrim(str_replace('\\', '/', trim($dr[1])), '/');
            if (stripos($domain, 'localhost') === 0 || strcasecmp($docroot, $htdocsNorm) === 0) {
                continue;
            }
            $slug = basename($docroot);
            if (preg_match('/logs\/([A-Za-z0-9._-]+)_error\.log/i', $body, $lg)) {
                $slug = $lg[1];
            }
            $crtPath = XAMPP_CERTS . DIRECTORY_SEPARATOR . $domain . '.crt';
            $cert = cert_info($crtPath);
            $issues = log_issues(XAMPP_ROOT . '/apache/logs/' . $slug . '_error.log');
            $size = project_size_cached($docroot);
            $sites[$domain] = [
                'domain'        => $domain,
                'folder'        => basename($docroot),
                'slug'          => $slug,
                'docroot'       => $docroot,
                'docroot_exists' => is_dir($docroot),
                'has_cert'      => is_file($crtPath),
                'cert_expires'  => $cert['expires'] ?? null,
                'cert_days'     => $cert['days_left'] ?? null,
                'stack'         => detect_stack($docroot),
                'errors'        => $issues['errors'],
                'warnings'      => $issues['warnings'],
                'size'          => $size['size'] ?? null,
                'size_capped'   => $size['capped'] ?? false,
                'modified'      => is_dir($docroot) ? (int) filemtime($docroot) : null,
                'configured'    => true,
                'status'        => 'unknown',
                'code'          => 0,
                'time_ms'       => null,
            ];
        }
    }

    $configuredFolders = array_map(static fn($s) => strtolower($s['folder']), $sites);
    foreach ((array) @scandir(XAMPP_HTDOCS) as $entry) {
        if ($entry === '' || $entry[0] === '.' || in_array(strtolower($entry), DASH_SKIP_FOLDERS, true)) {
            continue;
        }
        $full = XAMPP_HTDOCS . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($full) || in_array(strtolower($entry), $configuredFolders, true)) {
            continue;
        }
        $uSize = project_size_cached($full);
        $sites['_folder_' . $entry] = [
            'domain'        => null,
            'folder'        => $entry,
            'slug'          => $entry,
            'docroot'       => str_replace('\\', '/', $full),
            'docroot_exists' => true,
            'has_cert'      => false,
            'cert_expires'  => null,
            'cert_days'     => null,
            'stack'         => detect_stack($full),
            'errors'        => 0,
            'warnings'      => 0,
            'size'          => $uSize['size'] ?? null,
            'size_capped'   => $uSize['capped'] ?? false,
            'modified'      => (int) filemtime($full),
            'configured'    => false,
            'status'        => 'unconfigured',
            'code'          => 0,
            'time_ms'       => null,
        ];
    }

    site_reachability($sites);

    uasort($sites, static function (array $a, array $b): int {
        if ($a['configured'] !== $b['configured']) {
            return $a['configured'] ? -1 : 1;
        }
        return strcasecmp((string) ($a['domain'] ?? $a['folder']), (string) ($b['domain'] ?? $b['folder']));
    });

    return array_values($sites);
}

/**
 * Resolve live HTTPS reachability for configured sites in parallel.
 *
 * @param array<string,array<string,mixed>> $sites
 */
function site_reachability(array &$sites): void
{
    if (!function_exists('curl_multi_init')) {
        return;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($sites as $key => $site) {
        if (empty($site['domain'])) {
            continue;
        }
        $ch = curl_init('https://' . $site['domain'] . '/');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $key => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $sites[$key]['code'] = $code;
        $sites[$key]['status'] = $code > 0 ? 'up' : 'down';
        $sites[$key]['time_ms'] = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}

/**
 * Health of the local services (Apache, MySQL/MariaDB) and their ports.
 *
 * @return array<string,array<string,mixed>>
 */
function collect_services(): array
{
    $services = [];

    $services['apache'] = [
        'name'   => 'Apache',
        'up'     => true,
        'detail' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Apache'),
        'ports'  => ['80' => port_open('127.0.0.1', 80), '443' => port_open('127.0.0.1', 443)],
    ];

    $mysqlUp = port_open('127.0.0.1', 3306, 0.6);
    $mysqlVer = '';
    if ($mysqlUp && function_exists('mysqli_connect')) {
        $conn = @mysqli_connect('127.0.0.1', 'root', '', null, 3306);
        if ($conn instanceof mysqli) {
            $mysqlVer = (string) mysqli_get_server_info($conn);
            mysqli_close($conn);
        }
    }
    $services['mysql'] = [
        'name'   => 'MySQL / MariaDB',
        'up'     => $mysqlUp,
        'detail' => $mysqlVer !== '' ? $mysqlVer : ($mysqlUp ? 'Listening on :3306' : 'Not running'),
        'ports'  => ['3306' => $mysqlUp],
    ];

    return $services;
}

/**
 * Curated system information (no full phpinfo dump).
 *
 * @return array<string,mixed>
 */
function collect_system(): array
{
    $drive = substr(XAMPP_ROOT, 0, 1) . ':\\';
    $free = (float) (@disk_free_space($drive) ?: 0);
    $total = (float) (@disk_total_space($drive) ?: 0);

    $wanted = ['mysqli', 'curl', 'gd', 'mbstring', 'openssl', 'pdo_mysql', 'zip', 'intl'];
    $extensions = [];
    foreach ($wanted as $ext) {
        $extensions[$ext] = extension_loaded($ext);
    }

    return [
        'php_version'   => PHP_VERSION,
        'memory_limit'  => (string) ini_get('memory_limit'),
        'max_exec'      => (string) ini_get('max_execution_time'),
        'upload_max'    => (string) ini_get('upload_max_filesize'),
        'extensions'    => $extensions,
        'ext_total'     => count(get_loaded_extensions()),
        'disk_free'     => $free,
        'disk_total'    => $total,
        'disk_used'     => max(0.0, $total - $free),
        'drive'         => rtrim($drive, '\\'),
        'os'            => php_uname('s') . ' ' . php_uname('r'),
        'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'server_time'   => date('Y-m-d H:i:s'),
        'xampp_root'    => str_replace('\\', '/', XAMPP_ROOT),
        'has_phpmyadmin' => is_dir(XAMPP_ROOT . DIRECTORY_SEPARATOR . 'phpMyAdmin') || is_dir(XAMPP_HTDOCS . DIRECTORY_SEPARATOR . 'phpmyadmin'),
    ];
}

/**
 * User databases with table count and on-disk size. Empty if MySQL is down.
 *
 * @return array<int,array<string,mixed>>
 */
function collect_databases(): array
{
    if (!function_exists('mysqli_connect')) {
        return [];
    }
    $conn = @mysqli_connect('127.0.0.1', 'root', '', null, 3306);
    if (!$conn instanceof mysqli) {
        return [];
    }
    $system = ['information_schema', 'performance_schema', 'mysql', 'sys', 'phpmyadmin', 'test'];
    $sql = 'SELECT s.SCHEMA_NAME AS name,'
        . ' COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH), 0) AS size,'
        . ' COUNT(t.TABLE_NAME) AS tables'
        . ' FROM information_schema.SCHEMATA s'
        . ' LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME'
        . ' GROUP BY s.SCHEMA_NAME ORDER BY s.SCHEMA_NAME';
    $rows = [];
    $res = @mysqli_query($conn, $sql);
    if ($res instanceof mysqli_result) {
        while ($r = mysqli_fetch_assoc($res)) {
            if (in_array(strtolower((string) $r['name']), $system, true)) {
                continue;
            }
            $rows[] = [
                'name'   => (string) $r['name'],
                'size'   => (float) $r['size'],
                'tables' => (int) $r['tables'],
            ];
        }
        mysqli_free_result($res);
    }
    mysqli_close($conn);
    return $rows;
}

/**
 * Recent error-log tails: the Apache global log plus each site's *_error.log.
 *
 * @param array<int,array<string,mixed>> $sites
 * @return array<int,array<string,mixed>>
 */
function collect_logs(array $sites): array
{
    $dir = XAMPP_ROOT . '/apache/logs';
    $logs = [['key' => 'apache', 'name' => 'Apache (global)', 'lines' => tail_lines($dir . '/error.log', 12)]];

    $phpLog = (string) ini_get('error_log');
    if ($phpLog !== '' && strtolower($phpLog) !== 'syslog') {
        $logs[] = ['key' => 'php', 'name' => 'PHP error log', 'lines' => tail_lines($phpLog, 12)];
    }

    foreach ($sites as $site) {
        if (empty($site['configured']) || empty($site['slug'])) {
            continue;
        }
        $path = $dir . '/' . $site['slug'] . '_error.log';
        if (is_file($path) && (int) @filesize($path) > 0) {
            $logs[] = ['key' => (string) $site['slug'], 'name' => (string) $site['domain'], 'lines' => tail_lines($path, 10)];
        }
    }
    return $logs;
}

/**
 * At-a-glance roll-up for the hero bar.
 *
 * @return array<string,mixed>
 */
function build_summary(array $sites, array $services, array $system): array
{
    $configured = array_filter($sites, static fn($s) => $s['configured']);
    $up = array_filter($configured, static fn($s) => $s['status'] === 'up');
    $svcUp = array_filter($services, static fn($s) => $s['up']);
    $allServices = count($svcUp) === count($services);
    $allSites = count($up) === count($configured);

    return [
        'sites_total'    => count($configured),
        'sites_up'       => count($up),
        'sites_down'     => count($configured) - count($up),
        'services_total' => count($services),
        'services_up'    => count($svcUp),
        'disk_pct'       => $system['disk_total'] > 0 ? (int) round($system['disk_used'] / $system['disk_total'] * 100) : 0,
        'overall'        => !$allServices ? 'down' : ($allSites ? 'ok' : 'warn'),
    ];
}

/**
 * Full snapshot used by both the JSON API and the server-rendered first paint.
 *
 * @return array<string,mixed>
 */
function collect_snapshot(): array
{
    $sites = collect_sites();
    $services = collect_services();
    $system = collect_system();

    return [
        'sites'     => $sites,
        'services'  => $services,
        'system'    => $system,
        'databases' => collect_databases(),
        'logs'      => collect_logs($sites),
        'summary'   => build_summary($sites, $services, $system),
        'generated' => date('c'),
    ];
}
