<?php
declare(strict_types=1);

/**
 * SSH config manager engine.
 *
 * Apache runs as LocalSystem, so PHP's USERPROFILE is the SYSTEM account — not the
 * person using the dashboard. We therefore resolve the real user's ~/.ssh/config by
 * enumerating %SystemDrive%\Users (skipping system profiles). Nothing is hardcoded,
 * and writes are constrained to <realProfile>/.ssh/config so this stays safe when
 * the dashboard is shared.
 */

define('SSH_DRIVE', str_replace('\\', '/', getenv('SystemDrive') ?: 'C:'));
define('SSH_USERS', SSH_DRIVE . '/Users');
define('SSH_PREF_FILE', dirname(__DIR__) . '/.config/ssh.json');

const SSH_SYS_PROFILES = ['Public', 'Default', 'Default User', 'All Users', 'defaultuser0', 'WDAGUtilityAccount'];

class SshError extends RuntimeException
{
}

/** Real (non-system) user profiles: [username => home-path]. */
function ssh_real_homes(): array
{
    $homes = [];
    foreach (glob(SSH_USERS . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if ($name === '' || $name[0] === '.' || in_array($name, SSH_SYS_PROFILES, true)) {
            continue;
        }
        $homes[$name] = str_replace('\\', '/', $dir);
    }
    return $homes;
}

/** All candidate profiles with their ~/.ssh/config status (for the picker). */
function ssh_candidates(): array
{
    $out = [];
    foreach (ssh_real_homes() as $user => $home) {
        $cfg = $home . '/.ssh/config';
        $out[] = [
            'user'       => $user,
            'config'     => $cfg,
            'has_ssh'    => is_dir($home . '/.ssh'),
            'has_config' => is_file($cfg),
        ];
    }
    return $out;
}

/** Home directory for a user, only if it's a real enumerated profile (guards writes). */
function ssh_home_for_user(string $user): string
{
    $homes = ssh_real_homes();
    if ($user === '' || !isset($homes[$user])) {
        throw new SshError('Unknown or invalid user profile.');
    }
    return $homes[$user];
}

/** Remembered profile choice. */
function ssh_pref(): ?string
{
    $j = @json_decode((string) @file_get_contents(SSH_PREF_FILE), true);
    $u = is_array($j) ? (string) ($j['user'] ?? '') : '';
    return $u !== '' ? $u : null;
}
function ssh_set_pref(string $user): void
{
    $dir = dirname(SSH_PREF_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents(SSH_PREF_FILE, json_encode(['user' => $user]));
}

/** Pick the active profile: explicit request → remembered → first with a config → first real. */
function ssh_active(?string $wantUser = null): ?array
{
    $cands = ssh_candidates();
    if (!$cands) {
        return null;
    }
    $byUser = [];
    foreach ($cands as $c) {
        $byUser[$c['user']] = $c;
    }
    if ($wantUser !== null && isset($byUser[$wantUser])) {
        return $byUser[$wantUser];
    }
    $pref = ssh_pref();
    if ($pref !== null && isset($byUser[$pref])) {
        return $byUser[$pref];
    }
    foreach ($cands as $c) {
        if ($c['has_config']) {
            return $c;
        }
    }
    return $cands[0];
}

/** Parse Host blocks (best-effort; the raw editor stays the source of truth). */
function ssh_parse(string $content): array
{
    $hosts = [];
    $cur = null;
    foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') {
            continue;
        }
        if (!preg_match('/^(\S+)[\s=]+(.*)$/', $t, $m)) {
            continue;
        }
        $key = $m[1];
        $val = trim($m[2]);
        if (strcasecmp($key, 'Host') === 0) {
            if ($cur !== null) {
                $hosts[] = $cur;
            }
            $cur = ['host' => $val, 'options' => []];
        } elseif ($cur !== null) {
            $cur['options'][$key] = $val;
        }
    }
    if ($cur !== null) {
        $hosts[] = $cur;
    }
    return $hosts;
}

/** Backup the config next to itself, keeping the last 5. */
function ssh_backup(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }
    $dest = $path . '.bak-' . date('Ymd-His');
    @copy($path, $dest);
    foreach (array_slice(array_reverse(glob($path . '.bak-*') ?: []), 5) as $old) {
        @unlink($old);
    }
    return $dest;
}

/** Read + parse the active profile's config. */
function ssh_load(?string $wantUser = null): array
{
    $active = ssh_active($wantUser);
    $cands = ssh_candidates();
    if ($active === null) {
        return ['ok' => true, 'active' => null, 'path' => null, 'exists' => false, 'content' => '', 'hosts' => [], 'candidates' => $cands];
    }
    $content = is_file($active['config']) ? (string) @file_get_contents($active['config']) : '';
    return [
        'ok'         => true,
        'active'     => $active['user'],
        'path'       => $active['config'],
        'exists'     => $active['has_config'],
        'content'    => $content,
        'hosts'      => ssh_parse($content),
        'candidates' => $cands,
    ];
}

/** Write <realProfile>/.ssh/config (backup first, remember the choice). */
function ssh_save(string $user, string $content): array
{
    $home = ssh_home_for_user($user);
    $dir = $home . '/.ssh';
    $path = $dir . '/config';
    $log = [];
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true)) {
            throw new SshError('Could not create ' . $dir);
        }
        $log[] = ['level' => 'ok', 'msg' => 'Created ' . $dir];
    }
    $bak = ssh_backup($path);
    if ($bak !== null) {
        $log[] = ['level' => 'ok', 'msg' => 'Backed up existing config → ' . basename($bak)];
    }
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    if (@file_put_contents($path, $content) === false) {
        throw new SshError('Could not write ' . $path . ' (permission denied).');
    }
    ssh_set_pref($user);
    $log[] = ['level' => 'ok', 'msg' => 'Saved ' . $path . ' (' . strlen($content) . ' bytes)'];
    return ['ok' => true, 'log' => $log, 'message' => 'SSH config saved for ' . $user . '.'];
}
