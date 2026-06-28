<?php
declare(strict_types=1);

/**
 * DB environment sync — Phase 1 (read-only compare).
 * Connections are opened READ-ONLY. Credentials are passed in per call and are
 * never stored or logged. Only the secret-free environment list is persisted.
 */

require_once __DIR__ . '/paths.php';

const SYNC_ROLES = ['local', 'staging', 'production'];

function sync_config_path(): string
{
    $dir = __DIR__ . '/../.config';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/environments.json';
}

/** Environments are organised into named groups (one group per project/site). */
function default_groups(): array
{
    return [[
        'name' => 'example',
        'environments' => [
            ['role' => 'local',      'host' => '127.0.0.1',     'port' => 3306, 'db' => ''],
            ['role' => 'staging',    'host' => '100.110.57.68', 'port' => 3306, 'db' => ''],
            ['role' => 'production', 'host' => '100.110.57.68', 'port' => 3306, 'db' => ''],
        ],
    ]];
}

/** @return array<int,array<string,mixed>> */
function load_groups(): array
{
    $path = sync_config_path();
    if (!is_file($path)) {
        @file_put_contents($path, json_encode(default_groups(), JSON_PRETTY_PRINT));
        return default_groups();
    }
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data) || $data === []) {
        return default_groups();
    }
    // Migrate the legacy flat format (entries with no 'environments' key) into one group.
    if (is_array($data[0] ?? null) && !array_key_exists('environments', $data[0])) {
        $data = [['name' => 'imported', 'environments' => $data]];
    }
    return $data;
}

/** Persist groups — sanitized, never any credentials. */
function save_groups(array $groups): array
{
    $clean = [];
    foreach ($groups as $g) {
        $name = trim((string) ($g['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $envs = [];
        foreach ((array) ($g['environments'] ?? []) as $e) {
            $host = trim((string) ($e['host'] ?? ''));
            if ($host === '') {
                continue;
            }
            $envs[] = [
                'role' => in_array(($e['role'] ?? ''), SYNC_ROLES, true) ? $e['role'] : 'staging',
                'host' => substr($host, 0, 120),
                'port' => max(1, (int) ($e['port'] ?? 3306)),
                'db'   => substr(trim((string) ($e['db'] ?? '')), 0, 80),
            ];
        }
        $clean[] = ['name' => substr($name, 0, 60), 'environments' => $envs];
    }
    @file_put_contents(sync_config_path(), json_encode($clean, JSON_PRETTY_PRINT));
    return load_groups();
}

/** Flat list of environments across all groups, each with a stable id + label. */
function list_environments(): array
{
    $out = [];
    foreach (load_groups() as $gi => $g) {
        $gname = (string) ($g['name'] ?? ('Group ' . ($gi + 1)));
        foreach ((array) ($g['environments'] ?? []) as $ei => $e) {
            $role = (string) ($e['role'] ?? 'staging');
            $out[] = [
                'id'    => $gi . '.' . $ei,
                'group' => $gname,
                'role'  => $role,
                'host'  => (string) ($e['host'] ?? ''),
                'port'  => (int) ($e['port'] ?? 3306),
                'db'    => (string) ($e['db'] ?? ''),
                'label' => $gname . ' · ' . $role,
            ];
        }
    }
    return $out;
}

function find_environment(string $id): ?array
{
    foreach (list_environments() as $e) {
        if ($e['id'] === $id) {
            return $e;
        }
    }
    return null;
}

/** Production targets keyed by host:port:db — a hard write denylist for data sync. */
function production_targets(): array
{
    $out = [];
    foreach (list_environments() as $e) {
        if ($e['role'] === 'production' && $e['db'] !== '') {
            $out[] = strtolower($e['host'] . ':' . $e['port'] . ':' . $e['db']);
        }
    }
    return $out;
}

/** True if this exact (host,port,db) is configured anywhere as production. */
function is_production_target(array $env): bool
{
    $key = strtolower(($env['host'] ?? '') . ':' . ($env['port'] ?? '') . ':' . ($env['db'] ?? ''));
    return in_array($key, production_targets(), true);
}

/**
 * Open a mysqli connection (credentials not retained). Read-only by default;
 * writes are only ever requested by data-sync (target local/staging, prod denied)
 * and by the migration runner (which may target production via reviewed files).
 */
function sync_connect(array $env, string $user, string $pass, bool $readonly = true): mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = mysqli_init();
    if (!$conn) {
        throw new RuntimeException('Could not initialise MySQL.');
    }
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 6);
    $db = (string) ($env['db'] ?? '');
    $ok = @mysqli_real_connect($conn, (string) $env['host'], $user, $pass, $db !== '' ? $db : null, (int) $env['port']);
    if (!$ok) {
        throw new RuntimeException(mysqli_connect_error() ?: 'Connection failed.');
    }
    if ($readonly) {
        @mysqli_query($conn, 'SET SESSION TRANSACTION READ ONLY');
    }
    return $conn;
}

/** Backtick-quote an identifier. */
function qid(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/** Read tables → columns/indexes/approx-rows for a database. */
function read_schema(mysqli $conn, string $db): array
{
    $esc = mysqli_real_escape_string($conn, $db);
    $tables = [];

    $res = mysqli_query($conn, "SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA='$esc' AND TABLE_TYPE='BASE TABLE'");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $tables[$r['TABLE_NAME']] = ['columns' => [], 'indexes' => [], 'rows' => (int) $r['TABLE_ROWS']];
    }
    if ($res) {
        mysqli_free_result($res);
    }

    $res = mysqli_query($conn, "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$esc' ORDER BY TABLE_NAME, ORDINAL_POSITION");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        if (!isset($tables[$r['TABLE_NAME']])) {
            continue;
        }
        $sig = $r['COLUMN_TYPE']
            . ($r['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL')
            . ($r['EXTRA'] !== '' ? ' ' . $r['EXTRA'] : '')
            . ($r['COLUMN_DEFAULT'] !== null ? ' default ' . $r['COLUMN_DEFAULT'] : '');
        $tables[$r['TABLE_NAME']]['columns'][$r['COLUMN_NAME']] = trim($sig);
    }
    if ($res) {
        mysqli_free_result($res);
    }

    $res = mysqli_query($conn, "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLS FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$esc' GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        if (!isset($tables[$r['TABLE_NAME']])) {
            continue;
        }
        $tables[$r['TABLE_NAME']]['indexes'][$r['INDEX_NAME']] = ((int) $r['NON_UNIQUE'] ? 'INDEX' : 'UNIQUE') . '(' . $r['COLS'] . ')';
    }
    if ($res) {
        mysqli_free_result($res);
    }

    return $tables;
}

/**
 * Diff two schemas (source vs target). "destructive" marks differences that, applied
 * source → target, would drop or alter existing structure (potential data loss).
 */
function diff_schemas(array $src, array $tgt): array
{
    $names = array_unique(array_merge(array_keys($src), array_keys($tgt)));
    sort($names);
    $rows = [];
    $differences = 0;

    foreach ($names as $t) {
        $inS = isset($src[$t]);
        $inT = isset($tgt[$t]);
        $entry = [
            'name' => $t, 'in_source' => $inS, 'in_target' => $inT,
            'rows_source' => $inS ? $src[$t]['rows'] : null,
            'rows_target' => $inT ? $tgt[$t]['rows'] : null,
            'cols' => [], 'idx' => [], 'status' => 'same', 'destructive' => false,
        ];

        if ($inS && !$inT) {
            $entry['status'] = 'only_source';
            $differences++;
        } elseif (!$inS && $inT) {
            $entry['status'] = 'only_target';
            $entry['destructive'] = true;
            $differences++;
        } else {
            $cs = $src[$t]['columns'];
            $ct = $tgt[$t]['columns'];
            foreach (array_unique(array_merge(array_keys($cs), array_keys($ct))) as $c) {
                $a = $cs[$c] ?? null;
                $b = $ct[$c] ?? null;
                if ($a !== null && $b === null) {
                    $entry['cols'][] = ['name' => $c, 'status' => 'only_source', 'detail' => $a];
                } elseif ($a === null && $b !== null) {
                    $entry['cols'][] = ['name' => $c, 'status' => 'only_target', 'detail' => $b];
                    $entry['destructive'] = true;
                } elseif ($a !== $b) {
                    $entry['cols'][] = ['name' => $c, 'status' => 'changed', 'detail' => $a . '  ◀▶  ' . $b];
                    $entry['destructive'] = true;
                }
            }
            $is = $src[$t]['indexes'];
            $it = $tgt[$t]['indexes'];
            foreach (array_unique(array_merge(array_keys($is), array_keys($it))) as $n) {
                $a = $is[$n] ?? null;
                $b = $it[$n] ?? null;
                if ($a !== null && $b === null) {
                    $entry['idx'][] = ['name' => $n, 'status' => 'only_source', 'detail' => $a];
                } elseif ($a === null && $b !== null) {
                    $entry['idx'][] = ['name' => $n, 'status' => 'only_target', 'detail' => $b];
                } elseif ($a !== $b) {
                    $entry['idx'][] = ['name' => $n, 'status' => 'changed', 'detail' => $a . ' ◀▶ ' . $b];
                }
            }
            if ($entry['cols'] !== [] || $entry['idx'] !== []) {
                $entry['status'] = 'diff';
                $differences++;
            }
        }
        $rows[] = $entry;
    }

    return ['tables' => $rows, 'differences' => $differences];
}

/* ====================  Phase 2 — sync to LOCAL  ==================== */

/** Full CREATE TABLE for a source table, with the AUTO_INCREMENT counter stripped. */
function source_create_table(mysqli $conn, string $db, string $table): ?string
{
    $res = mysqli_query($conn, 'SHOW CREATE TABLE ' . qid($db) . '.' . qid($table));
    if (!$res) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    $sql = $row['Create Table'] ?? null;
    return $sql !== null ? (string) preg_replace('/\s+AUTO_INCREMENT=\d+/', '', $sql) : null;
}

/** Parse column DDL lines out of a CREATE TABLE statement → [colName => "`col` <ddl>"]. */
function source_column_defs(string $createSql): array
{
    $defs = [];
    foreach (preg_split('/\r\n|\n/', $createSql) as $line) {
        $line = trim($line);
        if (preg_match('/^`((?:[^`]|``)+)`\s+.+$/', $line, $m)) {
            $defs[str_replace('``', '`', $m[1])] = rtrim($line, ',');
        }
    }
    return $defs;
}

/**
 * Build an additive schema-sync plan (source → local target): create missing tables,
 * add/modify columns. Drops are never generated — they're reported as skipped.
 */
function generate_plan(mysqli $srcConn, string $srcDb, array $diff): array
{
    $schema = [];
    $skipped = [];
    $candidates = [];
    foreach ($diff['tables'] as $t) {
        $name = $t['name'];
        if ($t['status'] === 'only_source') {
            $create = source_create_table($srcConn, $srcDb, $name);
            if ($create !== null) {
                $schema[] = $create;
                $candidates[] = $name;
            }
        } elseif ($t['status'] === 'only_target') {
            $skipped[] = 'table ' . $name . ' exists only in target — kept (never dropped)';
        } else {
            $candidates[] = $name;
            if ($t['status'] === 'diff') {
                $create = source_create_table($srcConn, $srcDb, $name);
                $defs = $create !== null ? source_column_defs($create) : [];
                foreach ($t['cols'] as $c) {
                    if ($c['status'] === 'only_source' && isset($defs[$c['name']])) {
                        $schema[] = 'ALTER TABLE ' . qid($name) . ' ADD COLUMN ' . $defs[$c['name']];
                    } elseif ($c['status'] === 'changed' && isset($defs[$c['name']])) {
                        $schema[] = 'ALTER TABLE ' . qid($name) . ' MODIFY COLUMN ' . $defs[$c['name']];
                    } elseif ($c['status'] === 'only_target') {
                        $skipped[] = 'column ' . $name . '.' . $c['name'] . ' exists only in target — kept';
                    }
                }
                foreach ($t['idx'] as $ix) {
                    $skipped[] = 'index ' . $name . '.' . $ix['name'] . ' differs — sync indexes manually';
                }
            }
        }
    }
    return ['schema_sql' => $schema, 'skipped' => $skipped, 'data_candidates' => $candidates];
}

/** TRUNCATE + INSERT statements to copy a table's rows from source into the target. */
function table_data_sql(mysqli $srcConn, string $srcDb, mysqli $tgtConn, string $table, int $cap = 50000): array
{
    $sql = ['TRUNCATE TABLE ' . qid($table)];
    $res = mysqli_query($srcConn, 'SELECT * FROM ' . qid($srcDb) . '.' . qid($table));
    if (!$res) {
        return ['sql' => $sql, 'rows' => 0, 'capped' => false];
    }
    $cols = implode(',', array_map(static fn($f) => qid($f->name), mysqli_fetch_fields($res)));
    $rows = 0;
    $capped = false;
    $batch = [];
    while ($r = mysqli_fetch_row($res)) {
        $vals = array_map(static fn($v) => $v === null ? 'NULL' : "'" . mysqli_real_escape_string($tgtConn, (string) $v) . "'", $r);
        $batch[] = '(' . implode(',', $vals) . ')';
        $rows++;
        if (count($batch) >= 500) {
            $sql[] = 'INSERT INTO ' . qid($table) . " ($cols) VALUES " . implode(',', $batch);
            $batch = [];
        }
        if ($rows >= $cap) {
            $capped = true;
            break;
        }
    }
    if ($batch) {
        $sql[] = 'INSERT INTO ' . qid($table) . " ($cols) VALUES " . implode(',', $batch);
    }
    mysqli_free_result($res);
    return ['sql' => $sql, 'rows' => $rows, 'capped' => $capped];
}

/** mysqldump a target DB (local or remote-over-Tailscale) to site-manager-backups before any write. */
function backup_db(string $host, int $port, string $user, string $pass, string $db): string
{
    $dir = XAMPP_ROOT . DIRECTORY_SEPARATOR . 'site-manager-backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . $db . '-presync-' . date('Ymd-His') . '.sql';
    $bin = XAMPP_ROOT . '/mysql/bin/mysqldump.exe';
    if (!is_file($bin) || !function_exists('proc_open')) {
        throw new RuntimeException('mysqldump / proc_open unavailable — cannot back up before writing.');
    }
    $cmd = [$bin, '-h', $host, '-P', (string) $port, '-u', $user, '--routines', '--events', '--databases', $db];
    $env = $pass !== '' ? array_merge(getenv(), ['MYSQL_PWD' => $pass]) : null;
    $fp = fopen($file, 'wb');
    $proc = proc_open($cmd, [1 => $fp, 2 => ['pipe', 'w']], $pipes, $dir, $env);
    if (!is_resource($proc)) {
        fclose($fp);
        throw new RuntimeException('Could not launch mysqldump.');
    }
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    fclose($fp);
    if ($code !== 0 || (int) @filesize($file) === 0) {
        throw new RuntimeException('Backup failed: ' . trim((string) $err));
    }
    return $file;
}

/** Dry-run: the schema SQL + skipped + data candidates to bring a LOCAL target in line. */
function build_plan(array $srcEnv, string $srcUser, string $srcPass, array $tgtEnv, string $tgtUser, string $tgtPass): array
{
    if (!in_array($tgtEnv['role'] ?? '', ['local', 'staging'], true) || is_production_target($tgtEnv)) {
        throw new RuntimeException('Data sync target must be local or staging — never production.');
    }
    $src = sync_connect($srcEnv, $srcUser, $srcPass, true);
    $srcSchema = read_schema($src, (string) $srcEnv['db']);
    $tgt = sync_connect($tgtEnv, $tgtUser, $tgtPass, true);
    $tgtSchema = read_schema($tgt, (string) $tgtEnv['db']);
    $diff = diff_schemas($srcSchema, $tgtSchema);
    $plan = generate_plan($src, (string) $srcEnv['db'], $diff);
    mysqli_close($src);
    mysqli_close($tgt);
    $plan['differences'] = $diff['differences'];
    return $plan;
}

/** Apply the plan to the LOCAL target: backup → schema → selected reference data. */
function apply_plan(array $srcEnv, string $srcUser, string $srcPass, array $tgtEnv, string $tgtUser, string $tgtPass, array $dataTables, string $confirm): array
{
    if (!in_array($tgtEnv['role'] ?? '', ['local', 'staging'], true) || is_production_target($tgtEnv)) {
        throw new RuntimeException('Data sync target must be local or staging — never production.');
    }
    $db = (string) $tgtEnv['db'];
    if ($confirm !== $db) {
        throw new RuntimeException('Type the target database name exactly to confirm.');
    }

    $src = sync_connect($srcEnv, $srcUser, $srcPass, true);
    $srcSchema = read_schema($src, (string) $srcEnv['db']);
    $tgt = sync_connect($tgtEnv, $tgtUser, $tgtPass, false);
    $tgtSchema = read_schema($tgt, $db);
    $plan = generate_plan($src, (string) $srcEnv['db'], diff_schemas($srcSchema, $tgtSchema));

    $backup = backup_db((string) $tgtEnv['host'], (int) $tgtEnv['port'], $tgtUser, $tgtPass, $db);

    $stmts = $plan['schema_sql'];
    $dataInfo = [];
    $allowed = array_flip($plan['data_candidates']);
    foreach ($dataTables as $table) {
        if (!isset($allowed[$table])) {
            continue;
        }
        $d = table_data_sql($src, (string) $srcEnv['db'], $tgt, $table);
        $stmts = array_merge($stmts, $d['sql']);
        $dataInfo[] = ['table' => $table, 'rows' => $d['rows'], 'capped' => $d['capped']];
    }

    @mysqli_query($tgt, 'SET FOREIGN_KEY_CHECKS=0');
    $executed = 0;
    $error = null;
    $failedSql = null;
    foreach ($stmts as $s) {
        if (!@mysqli_query($tgt, $s)) {
            $error = mysqli_error($tgt);
            $failedSql = $s;
            break;
        }
        $executed++;
    }
    @mysqli_query($tgt, 'SET FOREIGN_KEY_CHECKS=1');
    mysqli_close($src);
    mysqli_close($tgt);

    return [
        'ok' => $error === null,
        'executed' => $executed,
        'total' => count($stmts),
        'error' => $error,
        'failed_sql' => $failedSql,
        'backup' => basename($backup),
        'skipped' => $plan['skipped'],
        'data' => $dataInfo,
    ];
}
