<?php
declare(strict_types=1);

/** Auth endpoint — localhost + CSRF guarded. Manages the passphrase and unlock token. */

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
require_once __DIR__ . '/lib/auth.php';

if (!hash_equals(pulse_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token — reload the page.']);
    exit;
}

$action = (string) ($_POST['action'] ?? 'status');
try {
    if ($action === 'status') {
        $r = ['ok' => true, 'set' => pulse_auth_is_set(), 'unlocked' => pulse_unlock_valid((string) ($_POST['unlock'] ?? ''))];
    } elseif ($action === 'set') {
        if (pulse_auth_is_set()) {
            throw new RuntimeException('A passphrase is already set.');
        }
        pulse_auth_set((string) ($_POST['passphrase'] ?? ''));
        pulse_audit('auth.set');
        $r = ['ok' => true, 'token' => pulse_unlock_create()];
    } elseif ($action === 'unlock') {
        if (!pulse_auth_verify((string) ($_POST['passphrase'] ?? ''))) {
            sleep(1); // slow brute-force attempts
            pulse_audit('auth.unlock.fail');
            throw new RuntimeException('Incorrect passphrase.');
        }
        pulse_audit('auth.unlock');
        $r = ['ok' => true, 'token' => pulse_unlock_create()];
    } elseif ($action === 'lock') {
        pulse_unlock_clear();
        pulse_audit('auth.lock');
        $r = ['ok' => true];
    } else {
        throw new RuntimeException('Unknown action.');
    }
    echo json_encode($r);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
