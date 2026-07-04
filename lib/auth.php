<?php
declare(strict_types=1);

/**
 * Auth gate for the privileged (SYSTEM / remote) endpoints.
 *
 * The page's CSRF token is readable by any local process that can fetch the dashboard,
 * so on its own it can't stop a local user-level process from driving SYSTEM actions.
 * This adds a passphrase: it's set once (stored only as a hash), and entering it mints a
 * short-lived unlock token the client keeps in sessionStorage (never embedded in the page)
 * and sends with each privileged request. Read-only monitoring stays open on localhost.
 */

function pulse_auth_dir(): string
{
    $d = dirname(__DIR__) . '/.config';
    if (!is_dir($d)) {
        @mkdir($d, 0777, true);
    }
    return $d;
}
function pulse_auth_hash_file(): string
{
    return pulse_auth_dir() . '/.auth';
}
function pulse_unlock_file(): string
{
    return pulse_auth_dir() . '/.unlock';
}

function pulse_auth_is_set(): bool
{
    return is_file(pulse_auth_hash_file()) && trim((string) @file_get_contents(pulse_auth_hash_file())) !== '';
}
function pulse_auth_set(string $passphrase): void
{
    if (strlen($passphrase) < 6) {
        throw new RuntimeException('Passphrase must be at least 6 characters.');
    }
    @file_put_contents(pulse_auth_hash_file(), password_hash($passphrase, PASSWORD_DEFAULT));
}
function pulse_auth_verify(string $passphrase): bool
{
    return pulse_auth_is_set() && password_verify($passphrase, trim((string) @file_get_contents(pulse_auth_hash_file())));
}

const PULSE_UNLOCK_TTL = 28800; // 8 hours

function pulse_unlock_create(): string
{
    $token = bin2hex(random_bytes(32));
    @file_put_contents(pulse_unlock_file(), json_encode(['token' => $token, 'expires' => time() + PULSE_UNLOCK_TTL]));
    return $token;
}
function pulse_unlock_valid(string $token): bool
{
    if ($token === '') {
        return false;
    }
    $j = @json_decode((string) @file_get_contents(pulse_unlock_file()), true);
    if (!is_array($j) || empty($j['token']) || (int) ($j['expires'] ?? 0) < time()) {
        return false;
    }
    return hash_equals((string) $j['token'], $token);
}
function pulse_unlock_clear(): void
{
    @unlink(pulse_unlock_file());
}

/** Guard for privileged endpoints — emits a JSON lock response and exits if not unlocked. */
function pulse_require_unlock(): void
{
    if (!pulse_auth_is_set()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'locked' => true, 'needs_setup' => true, 'error' => 'Set a passphrase to enable privileged actions.']);
        exit;
    }
    if (!pulse_unlock_valid((string) ($_POST['unlock'] ?? ''))) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'locked' => true, 'error' => 'Locked — enter your passphrase to continue.']);
        exit;
    }
}

/** Append a redacted line to the audit log. */
function pulse_audit(string $action, array $detail = []): void
{
    $dir = dirname(__DIR__) . '/.log';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    foreach (['csrf', 'unlock', 'passphrase', 'pass', 'password', 'source_pass', 'target_pass', 'staging_pass', 'content'] as $k) {
        if (array_key_exists($k, $detail)) {
            $detail[$k] = '***';
        }
    }
    $line = date('c') . "\t" . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\t" . $action . "\t" . json_encode($detail) . "\n";
    @file_put_contents($dir . '/audit.log', $line, FILE_APPEND | LOCK_EX);
}
