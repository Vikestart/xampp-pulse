<?php
declare(strict_types=1);

/** PHP-dev / mail endpoint — localhost + Origin + CSRF + unlock guarded. */

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
require_once __DIR__ . '/lib/sites.php';
require_once __DIR__ . '/lib/phpdev.php';
require_once __DIR__ . '/lib/auth.php';

if (!hash_equals(pulse_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token — reload the page and try again.']);
    exit;
}

pulse_require_unlock();

$action = (string) ($_POST['action'] ?? 'mail_status');
pulse_audit('dev.' . $action, $_POST);

try {
    if ($action === 'mail_status') {
        $r = ['ok' => true, 'on' => pd_mail_is_on(), 'count' => pd_mail_count()];
    } elseif ($action === 'mail_enable') {
        $r = pd_mail_enable();
    } elseif ($action === 'mail_disable') {
        $r = pd_mail_disable();
    } elseif ($action === 'mail_list') {
        $r = ['ok' => true, 'on' => pd_mail_is_on(), 'mail' => pd_mail_list()];
    } elseif ($action === 'mail_read') {
        $r = pd_mail_read((string) ($_POST['id'] ?? ''));
    } elseif ($action === 'mail_delete') {
        $r = pd_mail_delete((string) ($_POST['id'] ?? ''));
    } elseif ($action === 'mail_clear') {
        $r = pd_mail_clear();
    } elseif ($action === 'ini_read') {
        $r = ['ok' => true, 'content' => pd_ini_read()];
    } elseif ($action === 'ini_save') {
        $r = pd_ini_save((string) ($_POST['content'] ?? ''));
    } elseif ($action === 'xdebug_status') {
        $r = ['ok' => true] + pd_xdebug_status();
    } elseif ($action === 'xdebug_enable') {
        $r = pd_xdebug_enable();
    } elseif ($action === 'xdebug_disable') {
        $r = pd_xdebug_disable();
    } elseif ($action === 'task_list') {
        $r = ['ok' => true, 'tasks' => pd_task_list((string) ($_POST['folder'] ?? ''))];
    } elseif ($action === 'task_start') {
        $r = pd_task_start((string) ($_POST['folder'] ?? ''), (string) ($_POST['task'] ?? ''));
    } elseif ($action === 'task_poll') {
        $r = pd_task_poll((string) ($_POST['id'] ?? ''));
    } else {
        throw new SiteError('Unknown action.');
    }
    echo pulse_json($r);
} catch (Throwable $e) {
    echo pulse_json(['ok' => false, 'error' => $e->getMessage()]);
}
