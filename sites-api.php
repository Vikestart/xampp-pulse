<?php
declare(strict_types=1);

/**
 * Site management endpoint — localhost-only AND CSRF-protected, because these
 * actions run as SYSTEM (write hosts file, trust certs, restart Apache).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden — localhost only.']);
    exit;
}

// Reject cross-origin POSTs (defence against a malicious site in the browser).
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && !in_array(parse_url($origin, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden — bad origin.']);
    exit;
}

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/sites.php';
require_once __DIR__ . '/lib/auth.php';

if (!hash_equals(pulse_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token — reload the page and try again.']);
    exit;
}

pulse_require_unlock();

$action = (string) ($_POST['action'] ?? '');
pulse_audit('sites.' . $action, $_POST);
try {
    if ($action === 'create') {
        $r = sx_create((string) ($_POST['domain'] ?? ''), (string) ($_POST['folder'] ?? ''), (string) ($_POST['slug'] ?? ''));
    } elseif ($action === 'rename') {
        $r = sx_rename((string) ($_POST['old'] ?? ''), (string) ($_POST['new'] ?? ''), (string) ($_POST['folder'] ?? ''));
    } elseif ($action === 'remove') {
        $r = sx_remove((string) ($_POST['domain'] ?? ''), !empty($_POST['keep_cert']));
    } elseif ($action === 'fix_index') {
        $r = sx_fix_root_index();
    } elseif ($action === 'fix_localhost_cert') {
        $r = sx_fix_localhost_cert();
    } elseif ($action === 'service') {
        $r = sx_service_control((string) ($_POST['service'] ?? ''), (string) ($_POST['op'] ?? ''));
    } elseif ($action === 'env_read') {
        $r = sx_env_read((string) ($_POST['folder'] ?? ''));
    } elseif ($action === 'env_save') {
        $r = sx_env_save((string) ($_POST['folder'] ?? ''), (string) ($_POST['content'] ?? ''));
    } elseif ($action === 'git_status') {
        $r = sx_git_status((string) ($_POST['folder'] ?? ''));
    } else {
        throw new SiteError('Unknown action.');
    }
    echo pulse_json($r);
} catch (Throwable $e) {
    echo pulse_json(['ok' => false, 'error' => $e->getMessage()]);
}
