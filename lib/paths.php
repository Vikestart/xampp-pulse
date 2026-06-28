<?php
declare(strict_types=1);

/**
 * Resolve the XAMPP root by walking up from this file until the Apache binary
 * is found, so the dashboard is portable regardless of install drive/location.
 */
function xampp_root(): string
{
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_file($dir . '/apache/bin/httpd.exe')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return 'C:\\xampp';
}

define('XAMPP_ROOT', xampp_root());
define('XAMPP_HTDOCS', XAMPP_ROOT . DIRECTORY_SEPARATOR . 'htdocs');
define('XAMPP_CERTS', XAMPP_ROOT . DIRECTORY_SEPARATOR . 'certs');
define('XAMPP_SSL_CONF', XAMPP_ROOT . '/apache/conf/extra/httpd-ssl.conf');
