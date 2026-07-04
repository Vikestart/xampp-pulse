<?php

/**
 * XAMPP Pulse local mail catcher.
 *
 * When mail catching is enabled, php.ini's sendmail_path points here, so PHP pipes every
 * outgoing mail() message to this script's stdin. We just save the raw message as an .eml
 * for the dashboard to display — nothing is ever actually sent.
 */

$raw = stream_get_contents(STDIN);
$dir = __DIR__ . '/../.config/mail';
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
}
$name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
@file_put_contents($dir . '/' . $name, (string) $raw);
