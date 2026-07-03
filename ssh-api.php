<?php
declare(strict_types=1);

/**
 * SSH config endpoint — localhost-only AND CSRF-protected. Reading exposes host
 * details and writing runs as SYSTEM, so both actions require the token.
 */

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
require_once __DIR__ . '/lib/ssh.php';
require_once __DIR__ . '/lib/sites.php';

if (!hash_equals(pulse_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token — reload the page and try again.']);
    exit;
}

$action = (string) ($_POST['action'] ?? 'load');
try {
    if ($action === 'load') {
        $user = (string) ($_POST['user'] ?? '');
        $r = ssh_load($user !== '' ? $user : null);
        $r['proto'] = sx_ssh_protocol_status();
    } elseif ($action === 'save') {
        $r = ssh_save((string) ($_POST['user'] ?? ''), (string) ($_POST['content'] ?? ''));
    } elseif ($action === 'proto_enable') {
        $r = sx_register_ssh_protocol();
    } elseif ($action === 'proto_disable') {
        $r = sx_unregister_ssh_protocol();
    } else {
        throw new SshError('Unknown action.');
    }
    echo pulse_json($r);
} catch (Throwable $e) {
    echo pulse_json(['ok' => false, 'error' => $e->getMessage()]);
}
