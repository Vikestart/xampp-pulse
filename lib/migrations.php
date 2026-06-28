<?php
declare(strict_types=1);

/**
 * Phase 4 — schema migrations UP (local → staging → production).
 * Reviewed forward-DDL files, tracked per environment in a `_migrations` table,
 * applied in order. Production requires a mandatory backup and every pending
 * migration to be verified on staging first.
 */

require_once __DIR__ . '/dbsync.php';

function migrations_root(): string
{
    $dir = __DIR__ . '/../.migrations';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function group_slug(string $name): string
{
    $s = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
    return trim($s, '-') ?: 'group';
}

function migrations_dir(string $group): string
{
    $dir = migrations_root() . '/' . group_slug($group);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

/** @return string[] migration filenames, ordered. */
function list_migration_files(string $group): array
{
    $files = array_map('basename', glob(migrations_dir($group) . '/*.sql') ?: []);
    sort($files);
    return $files;
}

function next_migration_number(string $group): int
{
    $max = 0;
    foreach (list_migration_files($group) as $f) {
        if (preg_match('/^(\d+)/', $f, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return $max + 1;
}

function save_migration(string $group, string $title, string $sql): string
{
    $num = str_pad((string) next_migration_number($group), 3, '0', STR_PAD_LEFT);
    $file = $num . '_' . group_slug($title) . '.sql';
    @file_put_contents(migrations_dir($group) . '/' . $file, $sql);
    return $file;
}

function read_migration(string $group, string $file): string
{
    $path = migrations_dir($group) . '/' . basename($file);
    return is_file($path) ? (string) @file_get_contents($path) : '';
}

function ensure_migrations_table(mysqli $conn): void
{
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `_migrations` (`migration` VARCHAR(255) NOT NULL, `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`migration`)) ENGINE=InnoDB");
}

/** @return array<string,string> migration => applied_at (empty if table missing). */
function applied_set(mysqli $conn): array
{
    $set = [];
    $res = @mysqli_query($conn, "SELECT migration, applied_at FROM `_migrations`");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $set[$r['migration']] = $r['applied_at'];
        }
        mysqli_free_result($res);
    }
    return $set;
}

/** Split a migration file into statements (strips -- and # line comments). */
function split_sql(string $sql): array
{
    $kept = [];
    foreach (preg_split('/\r\n|\n/', $sql) as $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '#')) {
            continue;
        }
        $kept[] = $line;
    }
    $out = [];
    foreach (preg_split('/;\s*(?:\r?\n|$)/', implode("\n", $kept)) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}

/** Applied/pending status of a group's migrations for one environment. */
function migration_status_for_env(string $group, array $env, string $user, string $pass): array
{
    $files = list_migration_files($group);
    $conn = sync_connect($env, $user, $pass, true);
    $applied = applied_set($conn);
    mysqli_close($conn);
    return [
        'files'   => $files,
        'applied' => array_keys($applied),
        'pending' => array_values(array_filter($files, static fn($f) => !isset($applied[$f]))),
    ];
}

/** Draft additive SQL to bring target's schema up to source's (for authoring migrations). */
function draft_migration_sql(array $srcEnv, string $srcUser, string $srcPass, array $tgtEnv, string $tgtUser, string $tgtPass): string
{
    $src = sync_connect($srcEnv, $srcUser, $srcPass, true);
    $ss = read_schema($src, (string) $srcEnv['db']);
    $tgt = sync_connect($tgtEnv, $tgtUser, $tgtPass, true);
    $ts = read_schema($tgt, (string) $tgtEnv['db']);
    $plan = generate_plan($src, (string) $srcEnv['db'], diff_schemas($ss, $ts));
    mysqli_close($src);
    mysqli_close($tgt);
    return $plan['schema_sql'] ? implode(";\n\n", $plan['schema_sql']) . ";\n" : '';
}

/**
 * Apply pending migrations to a target, in order.
 * Production: requires a staging env where every pending migration is already applied,
 * and always backs up first. All targets are backed up before writing.
 */
function apply_migrations(string $group, array $tgtEnv, string $tgtUser, string $tgtPass, ?array $stagingEnv, string $stagingUser, string $stagingPass, string $confirm): array
{
    $role = (string) ($tgtEnv['role'] ?? '');
    if (!in_array($role, ['local', 'staging', 'production'], true)) {
        throw new RuntimeException('Unknown target role.');
    }
    $db = (string) $tgtEnv['db'];
    if ($confirm !== $db) {
        throw new RuntimeException('Type the target database name exactly to confirm.');
    }
    $files = list_migration_files($group);
    if (!$files) {
        throw new RuntimeException('This group has no migrations.');
    }

    $tconn = sync_connect($tgtEnv, $tgtUser, $tgtPass, true);
    $applied = applied_set($tconn);
    mysqli_close($tconn);
    $pending = array_values(array_filter($files, static fn($f) => !isset($applied[$f])));
    if (!$pending) {
        return ['ok' => true, 'applied' => [], 'pending' => [], 'message' => 'Already up to date.', 'backup' => null];
    }

    if ($role === 'production') {
        if (!$stagingEnv) {
            throw new RuntimeException('Production requires a staging environment to verify against.');
        }
        $sconn = sync_connect($stagingEnv, $stagingUser, $stagingPass, true);
        $staged = applied_set($sconn);
        mysqli_close($sconn);
        $notStaged = array_values(array_filter($pending, static fn($f) => !isset($staged[$f])));
        if ($notStaged) {
            throw new RuntimeException('Apply to staging first — not verified there: ' . implode(', ', $notStaged));
        }
    }

    $backup = backup_db((string) $tgtEnv['host'], (int) $tgtEnv['port'], $tgtUser, $tgtPass, $db);

    $conn = sync_connect($tgtEnv, $tgtUser, $tgtPass, false);
    ensure_migrations_table($conn);
    $done = [];
    $error = null;
    $failed = null;
    foreach ($pending as $file) {
        $ok = true;
        foreach (split_sql(read_migration($group, $file)) as $stmt) {
            if (!@mysqli_query($conn, $stmt)) {
                $error = mysqli_error($conn);
                $failed = $file;
                $ok = false;
                break;
            }
        }
        if (!$ok) {
            break;
        }
        @mysqli_query($conn, "INSERT INTO `_migrations` (migration) VALUES ('" . mysqli_real_escape_string($conn, $file) . "')");
        $done[] = $file;
    }
    mysqli_close($conn);

    return [
        'ok'      => $error === null,
        'applied' => $done,
        'pending' => array_values(array_diff($pending, $done)),
        'error'   => $error,
        'failed'  => $failed,
        'backup'  => basename($backup),
    ];
}
