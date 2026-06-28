<?php
declare(strict_types=1);

/** Localhost-only phpinfo() for the dashboard's Quick actions. */
$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Forbidden — localhost only.');
}
phpinfo();
