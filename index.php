<?php
declare(strict_types=1);

/**
 * Fallback entry point — lets https://localhost/xampp-pulse/ serve the dashboard
 * directly, independent of the htdocs root index.php. Handy if the web root ever
 * stops pointing here (the dashboard shows a banner to re-point it).
 */
require __DIR__ . '/render.php';
