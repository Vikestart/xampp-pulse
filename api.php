<?php
declare(strict_types=1);

/**
 * Live status endpoint. Read-only, localhost-only. Returns the full snapshot
 * as JSON for the dashboard frontend to poll.
 */

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Forbidden — localhost only.']);
    exit;
}

require_once __DIR__ . '/lib/collectors.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__ . '/lib/helpers.php';
echo pulse_json(collect_snapshot());
