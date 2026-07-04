<?php
declare(strict_types=1);

/**
 * DB sync endpoint — Phase 1 (read-only compare). Localhost-only.
 * Credentials arrive via POST, are used for the single request, and are never
 * stored or logged.
 */

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
require_once __DIR__ . '/lib/dbsync.php';
require_once __DIR__ . '/lib/migrations.php';
require_once __DIR__ . '/lib/auth.php';

if (!hash_equals(pulse_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token — reload the page and try again.']);
    exit;
}

pulse_require_unlock();

$action = (string) ($_POST['action'] ?? 'envs');
pulse_audit('sync.' . $action, $_POST);

try {
    if ($action === 'envs') {
        echo pulse_json(['ok' => true, 'groups' => load_groups(), 'environments' => list_environments(), 'roles' => SYNC_ROLES]);
        exit;
    }

    if ($action === 'save_envs') {
        $groups = json_decode((string) ($_POST['groups'] ?? '[]'), true);
        if (!is_array($groups)) {
            throw new RuntimeException('Invalid groups payload.');
        }
        echo pulse_json(['ok' => true, 'groups' => save_groups($groups), 'environments' => list_environments()]);
        exit;
    }

    if ($action === 'compare') {
        $src = find_environment((string) ($_POST['source'] ?? ''));
        $tgt = find_environment((string) ($_POST['target'] ?? ''));
        if (!$src || !$tgt) {
            throw new RuntimeException('Choose a source and a target environment.');
        }
        if (($src['db'] ?? '') === '' || ($tgt['db'] ?? '') === '') {
            throw new RuntimeException('Both environments need a database name — edit them first.');
        }

        $out = [
            'ok' => true,
            'source' => ['label' => $src['label'], 'role' => $src['role'], 'db' => $src['db'], 'host' => $src['host'], 'error' => null, 'tables' => 0],
            'target' => ['label' => $tgt['label'], 'role' => $tgt['role'], 'db' => $tgt['db'], 'host' => $tgt['host'], 'error' => null, 'tables' => 0],
        ];

        $sSchema = $tSchema = null;
        try {
            $c = sync_connect($src, (string) ($_POST['source_user'] ?? 'root'), (string) ($_POST['source_pass'] ?? ''));
            $sSchema = read_schema($c, (string) $src['db']);
            mysqli_close($c);
            $out['source']['tables'] = count($sSchema);
        } catch (Throwable $e) {
            $out['source']['error'] = $e->getMessage();
        }
        try {
            $c = sync_connect($tgt, (string) ($_POST['target_user'] ?? 'root'), (string) ($_POST['target_pass'] ?? ''));
            $tSchema = read_schema($c, (string) $tgt['db']);
            mysqli_close($c);
            $out['target']['tables'] = count($tSchema);
        } catch (Throwable $e) {
            $out['target']['error'] = $e->getMessage();
        }

        if ($out['source']['error'] !== null || $out['target']['error'] !== null) {
            $out['ok'] = false;
            echo pulse_json($out);
            exit;
        }

        $diff = diff_schemas($sSchema, $tSchema);
        $out['diff'] = $diff;
        $out['summary'] = ['differences' => $diff['differences'], 'in_sync' => $diff['differences'] === 0];
        echo pulse_json($out);
        exit;
    }

    if ($action === 'plan') {
        $src = find_environment((string) ($_POST['source'] ?? ''));
        $tgt = find_environment((string) ($_POST['target'] ?? ''));
        if (!$src || !$tgt) {
            throw new RuntimeException('Choose a source and a target.');
        }
        if (($src['db'] ?? '') === '' || ($tgt['db'] ?? '') === '') {
            throw new RuntimeException('Both environments need a database name.');
        }
        $plan = build_plan($src, (string) ($_POST['source_user'] ?? 'root'), (string) ($_POST['source_pass'] ?? ''), $tgt, (string) ($_POST['target_user'] ?? 'root'), (string) ($_POST['target_pass'] ?? ''));
        echo pulse_json(['ok' => true, 'target' => ['db' => $tgt['db'], 'label' => $tgt['label']], 'plan' => $plan]);
        exit;
    }

    if ($action === 'apply') {
        $src = find_environment((string) ($_POST['source'] ?? ''));
        $tgt = find_environment((string) ($_POST['target'] ?? ''));
        if (!$src || !$tgt) {
            throw new RuntimeException('Choose a source and a target.');
        }
        $dataTables = json_decode((string) ($_POST['data_tables'] ?? '[]'), true);
        $report = apply_plan($src, (string) ($_POST['source_user'] ?? 'root'), (string) ($_POST['source_pass'] ?? ''), $tgt, (string) ($_POST['target_user'] ?? 'root'), (string) ($_POST['target_pass'] ?? ''), is_array($dataTables) ? $dataTables : [], (string) ($_POST['confirm'] ?? ''));
        echo pulse_json($report);
        exit;
    }

    if ($action === 'mig_list') {
        echo pulse_json(['ok' => true, 'files' => list_migration_files((string) ($_POST['group'] ?? $_GET['group'] ?? ''))]);
        exit;
    }

    if ($action === 'mig_read') {
        echo pulse_json(['ok' => true, 'sql' => read_migration((string) ($_POST['group'] ?? ''), (string) ($_POST['file'] ?? ''))]);
        exit;
    }

    if ($action === 'mig_save') {
        $group = (string) ($_POST['group'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        $sql = (string) ($_POST['sql'] ?? '');
        if ($group === '' || $title === '' || trim($sql) === '') {
            throw new RuntimeException('Group, title and SQL are all required.');
        }
        $file = save_migration($group, $title, $sql);
        echo pulse_json(['ok' => true, 'file' => $file, 'files' => list_migration_files($group)]);
        exit;
    }

    if ($action === 'mig_status') {
        $env = find_environment((string) ($_POST['env'] ?? ''));
        if (!$env) {
            throw new RuntimeException('Choose an environment.');
        }
        if (($env['db'] ?? '') === '') {
            throw new RuntimeException('That environment has no database name.');
        }
        $st = migration_status_for_env((string) ($_POST['group'] ?? ''), $env, (string) ($_POST['user'] ?? 'root'), (string) ($_POST['pass'] ?? ''));
        echo pulse_json(['ok' => true] + $st);
        exit;
    }

    if ($action === 'mig_draft') {
        $src = find_environment((string) ($_POST['source'] ?? ''));
        $tgt = find_environment((string) ($_POST['target'] ?? ''));
        if (!$src || !$tgt) {
            throw new RuntimeException('Choose a source and a target.');
        }
        if (($src['db'] ?? '') === '' || ($tgt['db'] ?? '') === '') {
            throw new RuntimeException('Both environments need a database name.');
        }
        $sql = draft_migration_sql($src, (string) ($_POST['source_user'] ?? 'root'), (string) ($_POST['source_pass'] ?? ''), $tgt, (string) ($_POST['target_user'] ?? 'root'), (string) ($_POST['target_pass'] ?? ''));
        echo pulse_json(['ok' => true, 'sql' => $sql]);
        exit;
    }

    if ($action === 'mig_apply') {
        $tgt = find_environment((string) ($_POST['target'] ?? ''));
        if (!$tgt) {
            throw new RuntimeException('Choose a target environment.');
        }
        $staging = find_environment((string) ($_POST['staging'] ?? ''));
        $r = apply_migrations(
            (string) ($_POST['group'] ?? ''),
            $tgt,
            (string) ($_POST['target_user'] ?? 'root'),
            (string) ($_POST['target_pass'] ?? ''),
            $staging ?: null,
            (string) ($_POST['staging_user'] ?? 'root'),
            (string) ($_POST['staging_pass'] ?? ''),
            (string) ($_POST['confirm'] ?? '')
        );
        echo pulse_json($r);
        exit;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    echo pulse_json(['ok' => false, 'error' => $e->getMessage()]);
}
