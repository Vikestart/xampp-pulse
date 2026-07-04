<?php
declare(strict_types=1);

/**
 * Live-server monitoring — CONFIG-DRIVEN so the shared tool has no hardcoded domains.
 * Each install keeps its own endpoint list in .config/monitors.json (secret-free,
 * gitignored). A check pings the URL (status + response time) and reads the peer
 * certificate for an expiry countdown.
 */

function live_config_path(): string
{
    return dirname(__DIR__) . '/.config/monitors.json';
}

function live_monitors_read(): array
{
    $j = @json_decode((string) @file_get_contents(live_config_path()), true);
    if (!is_array($j)) {
        return [];
    }
    return array_values(array_filter(array_map(static function ($m) {
        if (!is_array($m) || empty($m['url'])) {
            return null;
        }
        $url = (string) $m['url'];
        return ['label' => (string) ($m['label'] ?? ''), 'url' => $url];
    }, $j)));
}

function live_monitors_save(array $monitors): array
{
    $clean = [];
    foreach ($monitors as $m) {
        $url = trim((string) ($m['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $label = trim((string) ($m['label'] ?? ''));
        $clean[] = ['label' => $label !== '' ? $label : (string) parse_url($url, PHP_URL_HOST), 'url' => $url];
    }
    $dir = dirname(live_config_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents(live_config_path(), json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $clean;
}

/** Ping one endpoint: HTTP status + response time, and (https) peer-cert expiry. */
function live_check(string $url): array
{
    $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: 'https'));
    $host = (string) parse_url($url, PHP_URL_HOST);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

    $code = 0;
    $ms = 0;
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'XAMPP-Pulse',
        ]);
        $t0 = microtime(true);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $err = (string) curl_error($ch);
        curl_close($ch);
    }

    $certDays = null;
    $certExpires = null;
    if ($scheme === 'https' && $host !== '' && function_exists('stream_socket_client')) {
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false,
            'SNI_enabled' => true, 'peer_name' => $host,
        ]]);
        $c = @stream_socket_client("ssl://{$host}:{$port}", $eno, $estr, 6, STREAM_CLIENT_CONNECT, $ctx);
        if ($c) {
            $params = stream_context_get_params($c);
            $peer = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($peer && function_exists('openssl_x509_parse')) {
                $cert = @openssl_x509_parse($peer);
                if (!empty($cert['validTo_time_t'])) {
                    $certExpires = date('Y-m-d', (int) $cert['validTo_time_t']);
                    $certDays = (int) floor(((int) $cert['validTo_time_t'] - time()) / 86400);
                }
            }
            fclose($c);
        }
    }

    return [
        'url' => $url, 'up' => $code >= 200 && $code < 400, 'code' => $code, 'ms' => $ms,
        'cert_days' => $certDays, 'cert_expires' => $certExpires, 'error' => $err,
    ];
}

/** Check a URL only if it's one of the configured monitors (guards against SSRF via the endpoint). */
function live_check_configured(string $url): array
{
    foreach (live_monitors_read() as $m) {
        if ($m['url'] === $url) {
            return live_check($url);
        }
    }
    throw new RuntimeException('Not a configured endpoint.');
}
