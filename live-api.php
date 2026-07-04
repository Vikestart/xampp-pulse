<?php
declare(strict_types=1);

/** Live-server endpoint — localhost + Origin + CSRF + unlock guarded. */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden — localhost only.']);
    exit;
}
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && !in_array(parse_url($origin, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden — bad origin.']);
    exit;
}

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/live.php';
require_once __DIR__ . '/lib/auth.php';

if (!hash_equals(pulse_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token — reload the page and try again.']);
    exit;
}

pulse_require_unlock();

$action = (string) ($_POST['action'] ?? 'monitors_list');
pulse_audit('live.' . $action, $_POST);

try {
    if ($action === 'monitors_list') {
        $r = ['ok' => true, 'monitors' => live_monitors_read()];
    } elseif ($action === 'monitors_save') {
        $in = json_decode((string) ($_POST['monitors'] ?? '[]'), true);
        $r = ['ok' => true, 'monitors' => live_monitors_save(is_array($in) ? $in : [])];
    } elseif ($action === 'monitors_check') {
        $r = ['ok' => true] + live_check_configured((string) ($_POST['url'] ?? ''));
    } else {
        throw new RuntimeException('Unknown action.');
    }
    echo pulse_json($r);
} catch (Throwable $e) {
    echo pulse_json(['ok' => false, 'error' => $e->getMessage()]);
}
