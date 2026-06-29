<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/collectors.php';

$snap      = collect_snapshot();
$sites     = $snap['sites'];
$services  = $snap['services'];
$system    = $snap['system'];
$databases = $snap['databases'];
$logs      = $snap['logs'];
$summary   = $snap['summary'];

const HERO_TEXT = ['ok' => 'All systems operational', 'warn' => 'Some sites are down', 'down' => 'A service is down'];

function rt_class(int $ms): string
{
    return $ms < 200 ? 'rt-fast' : ($ms < 700 ? 'rt-mid' : 'rt-slow');
}

/** Sort weight — problems first (mirrors siteRank() in dashboard.js). */
function site_rank(array $s): int
{
    if (!$s['configured']) {
        return 4;
    }
    if ($s['status'] === 'down') {
        return 0;
    }
    if ((int) $s['errors'] > 0) {
        return 1;
    }
    if ((int) $s['warnings'] > 0) {
        return 2;
    }
    return 3;
}

/** Severity class for a log line (mirrors logSev() in dashboard.js). */
function log_sev(string $line): string
{
    if (preg_match('/Fatal error|Parse error|exception|:error\]/i', $line)) {
        return 'error';
    }
    if (preg_match('/Warning|Deprecated/i', $line)) {
        return 'warn';
    }
    if (preg_match('/Notice/i', $line)) {
        return 'notice';
    }
    return '';
}

function render_log_lines(array $lines): string
{
    if ($lines === []) {
        return '<div class="log-line empty">No recent entries.</div>';
    }
    $out = '';
    foreach ($lines as $line) {
        $out .= '<div class="log-line ' . log_sev($line) . '">' . esc($line) . '</div>';
    }
    return $out;
}

function render_service_card(array $s): string
{
    $cls = $s['up'] ? 'up' : 'down';
    $ports = '';
    foreach ($s['ports'] as $port => $open) {
        $ports .= '<span class="port ' . ($open ? 'on' : 'off') . '">' . esc((string) $port) . '</span>';
    }
    return '<article class="svc-card status-' . $cls . '">'
        . '<div class="svc-top"><span class="dot"></span><h3>' . esc($s['name']) . '</h3>'
        . '<span class="svc-state">' . ($s['up'] ? 'Running' : 'Stopped') . '</span></div>'
        . '<p class="svc-detail">' . esc($s['detail']) . '</p>'
        . '<div class="ports">' . $ports . '</div></article>';
}

/** One site card (mirrors siteCard() in dashboard.js). */
function render_site_card(array $s): string
{
    $key  = (string) ($s['domain'] ?? 'folder:' . $s['folder']);
    $name = (string) ($s['domain'] ?? $s['folder']);

    if ($s['configured']) {
        $badge = '<span class="badge">' . esc($s['code'] > 0 ? (string) $s['code'] : 'down') . '</span>';
        $title = '<a class="site-name" href="https://' . esc($s['domain']) . '/" target="_blank" rel="noopener" title="' . esc($s['domain']) . '">' . esc($s['domain']) . '</a>';
    } else {
        $badge = '<span class="badge muted">no vhost</span>';
        $title = '<span class="site-name" title="' . esc($s['folder']) . '">' . esc($s['folder']) . '</span>';
    }

    // Cert flag only when there's a problem (missing or expiring soon).
    $certFlag = '';
    if (!$s['has_cert']) {
        $certFlag = '<span class="cert-flag warn" title="No certificate"><i class="fa-solid fa-lock-open"></i></span>';
    } elseif ($s['cert_days'] !== null && $s['cert_days'] < 30) {
        $certFlag = '<span class="cert-flag err" title="Certificate expires in ' . (int) $s['cert_days'] . ' days"><i class="fa-solid fa-lock"></i></span>';
    }

    $issue = '';
    if ($s['configured'] && (int) $s['errors'] > 0) {
        $issue = '<button type="button" class="issue err" data-log="' . esc($s['slug']) . '" title="Errors in log — open Logs"><i class="fa-solid fa-circle-exclamation"></i> ' . (int) $s['errors'] . '</button>';
    } elseif ($s['configured'] && (int) $s['warnings'] > 0) {
        $issue = '<button type="button" class="issue warn" data-log="' . esc($s['slug']) . '" title="Warnings in log — open Logs"><i class="fa-solid fa-triangle-exclamation"></i> ' . (int) $s['warnings'] . '</button>';
    }

    $chip = $s['stack'] !== '' ? '<span class="chip">' . esc($s['stack']) . '</span>' : '';
    $rt = '';
    if ($s['configured'] && $s['status'] === 'up' && $s['time_ms'] !== null) {
        $rt = '<span class="rt ' . rt_class((int) $s['time_ms']) . '"><i class="fa-solid fa-gauge-high"></i> ' . (int) $s['time_ms'] . ' ms</span>';
    }
    $spark = $s['configured']
        ? '<div class="spark" data-spark="' . esc((string) $s['domain']) . '">' . str_repeat('<i class="ph"></i>', 20) . '</div>'
        : '';
    $meta = ($chip !== '' || $rt !== '') ? '<div class="site-meta">' . $chip . $rt . '</div>' : '';
    $issued = ((int) $s['errors'] > 0 || (int) $s['warnings'] > 0) ? '1' : '0';

    return '<article class="site-card status-' . esc($s['status']) . '" data-key="' . esc($key) . '" data-state="' . esc($s['status']) . '" data-issue="' . $issued . '" data-search="' . esc(strtolower($name . ' ' . $s['stack'])) . '" tabindex="0" role="button" aria-label="' . esc($name . ' — open details') . '">'
        . '<div class="site-top"><span class="dot"></span>' . $title . '<span class="site-right">' . $certFlag . $issue . $badge . '</span></div>'
        . $meta . $spark . '</article>';
}

function render_db_list(array $databases): string
{
    if ($databases === []) {
        return '<p class="empty">No user databases — or MySQL is not running.</p>';
    }
    $out = '';
    foreach ($databases as $db) {
        $href = 'http://localhost/phpmyadmin/index.php?route=/database/structure&db=' . rawurlencode($db['name']);
        $out .= '<a class="db-row" href="' . esc($href) . '" target="_blank" rel="noopener">'
            . '<span class="db-name"><i class="fa-solid fa-table"></i> ' . esc($db['name']) . '</span>'
            . '<span class="db-meta">' . (int) $db['tables'] . ' tables · ' . esc(human_bytes((float) $db['size'])) . '</span></a>';
    }
    return $out;
}

$diskPct  = $system['disk_total'] > 0 ? (int) round($system['disk_used'] / $system['disk_total'] * 100) : 0;
$firstLog = $logs[0]['lines'] ?? [];

// Problems first, then alphabetical (mirrors sortSites() in dashboard.js).
usort($sites, static fn(array $a, array $b): int =>
    (site_rank($a) <=> site_rank($b)) ?: strcmp((string) ($a['domain'] ?: $a['folder']), (string) ($b['domain'] ?: $b['folder'])));

$cfgSites  = array_filter($sites, static fn(array $s): bool => (bool) $s['configured']);
$chipCount = [
    'all'    => count($sites),
    'up'     => count(array_filter($cfgSites, static fn(array $s): bool => $s['status'] === 'up')),
    'down'   => count(array_filter($cfgSites, static fn(array $s): bool => $s['status'] === 'down')),
    'issues' => count(array_filter($cfgSites, static fn(array $s): bool => $s['status'] === 'down' || (int) $s['errors'] > 0 || (int) $s['warnings'] > 0)),
];

$tabs = [
    'sites'     => ['Sites', 'fa-globe'],
    'services'  => ['Services', 'fa-server'],
    'databases' => ['Databases', 'fa-database'],
    'logs'      => ['Logs', 'fa-file-lines'],
    'system'    => ['System', 'fa-microchip'],
];
?>
<!doctype html>
<html lang="en" data-theme="light" data-tab="sites">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#2563eb">
<title>XAMPP Pulse</title>
<link rel="manifest" href="/xampp-pulse/manifest.webmanifest">
<link rel="icon" type="image/svg+xml" href="/xampp-pulse/assets/img/icon.svg">
<link rel="icon" href="/favicon.ico">
<link rel="stylesheet" href="/xampp-pulse/assets/css/dashboard.css">
<script>(function(){try{var d=document.documentElement,t=localStorage.getItem('dash-theme'),b=localStorage.getItem('dash-tab');if(t)d.dataset.theme=t;if(b)d.dataset.tab=b;if(localStorage.getItem('dash-db-collapsed')==='1')d.dataset.dbcollapsed='1';}catch(e){}})();</script>
</head>
<body>
<div class="wrap">
    <header class="topbar">
        <div class="brand">
            <span class="brand-icon"><i class="fa-solid fa-gauge-high"></i></span>
            <div>
                <h1>XAMPP Pulse</h1>
                <p><?= esc($system['xampp_root']) ?> · PHP <?= esc($system['php_version']) ?></p>
            </div>
        </div>
        <div class="topbar-actions">
            <span class="updated" id="updated">live</span>
            <select id="interval" class="mini-select" aria-label="Refresh interval" title="Refresh interval">
                <option value="2000">2s</option>
                <option value="5000" selected>5s</option>
                <option value="10000">10s</option>
                <option value="30000">30s</option>
                <option value="0">Off</option>
            </select>
            <button class="icon-btn" id="notify" type="button" aria-label="Toggle notifications" title="Notify on outages"><i class="fa-solid fa-bell-slash"></i></button>
            <button class="icon-btn" id="refresh" type="button" aria-label="Refresh now"><i class="fa-solid fa-rotate"></i></button>
            <button class="icon-btn" id="theme-toggle" type="button" aria-label="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        </div>
    </header>

    <?php if (!pulse_root_index_ok()) { ?>
    <div class="rootbanner" id="root-banner" role="alert">
        <div class="rootbanner-msg">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span><b>localhost isn’t serving Pulse.</b> The web root <code>htdocs/index.php</code> doesn’t point to this dashboard.</span>
        </div>
        <button class="rootbanner-fix" id="root-fix" type="button"><i class="fa-solid fa-wrench"></i> Point it to Pulse</button>
    </div>
    <?php } ?>

    <?php if (!pulse_localhost_cert_ok()) { ?>
    <div class="rootbanner" id="cert-banner" role="alert">
        <div class="rootbanner-msg">
            <i class="fa-solid fa-shield-halved"></i>
            <span><b>localhost isn’t on a trusted certificate.</b> That’s why notifications keep switching off — browsers drop permission on untrusted origins. One click issues &amp; trusts a proper cert; Apache restarts briefly.</span>
        </div>
        <button class="rootbanner-fix" id="cert-fix" type="button"><i class="fa-solid fa-lock"></i> Trust localhost cert</button>
    </div>
    <?php } ?>

    <section class="hero status-<?= esc($summary['overall']) ?>" id="hero">
        <div class="hero-main">
            <span class="hero-dot"></span>
            <div>
                <h2 id="hero-title"><?= esc(HERO_TEXT[$summary['overall']]) ?></h2>
                <p id="hero-sub"><?= (int) $summary['sites_up'] ?> of <?= (int) $summary['sites_total'] ?> sites up · <?= (int) $summary['services_up'] ?>/<?= (int) $summary['services_total'] ?> services</p>
            </div>
        </div>
        <div class="hero-stats">
            <div class="stat"><b id="stat-up"><?= (int) $summary['sites_up'] ?></b><span>up</span></div>
            <div class="stat"><b id="stat-down"><?= (int) $summary['sites_down'] ?></b><span>down</span></div>
            <div class="stat"><b id="stat-svc"><?= (int) $summary['services_up'] ?>/<?= (int) $summary['services_total'] ?></b><span>services</span></div>
            <div class="stat"><b id="stat-disk"><?= (int) $summary['disk_pct'] ?>%</b><span>disk</span></div>
        </div>
    </section>

    <nav class="tabs" id="tabs" role="tablist" aria-label="Dashboard views">
        <?php foreach ($tabs as $key => [$label, $icon]) { ?>
            <button class="tab" data-tab="<?= esc($key) ?>" type="button"><i class="fa-solid <?= esc($icon) ?>"></i><span><?= esc($label) ?></span></button>
        <?php } ?>
    </nav>

    <section class="view" data-view="sites">
        <section class="panel">
            <div class="panel-head">
                <h2><i class="fa-solid fa-globe"></i> Sites <span class="count" id="site-count"><?= count(array_filter($sites, fn($s) => $s['configured'])) ?></span></h2>
                <div class="head-actions">
                    <button id="site-new" class="mini-btn" type="button"><i class="fa-solid fa-plus"></i> New site</button>
                    <input type="search" id="site-filter" class="filter" placeholder="Filter sites…" aria-label="Filter sites">
                </div>
            </div>
            <div class="status-filter" id="status-filter" role="group" aria-label="Filter sites by status">
                <button class="chip-btn is-active" data-filter="all" type="button">All <span class="chip-n"><?= (int) $chipCount['all'] ?></span></button>
                <button class="chip-btn" data-filter="up" type="button">Up <span class="chip-n"><?= (int) $chipCount['up'] ?></span></button>
                <button class="chip-btn" data-filter="down" type="button">Down <span class="chip-n"><?= (int) $chipCount['down'] ?></span></button>
                <button class="chip-btn" data-filter="issues" type="button">Issues <span class="chip-n"><?= (int) $chipCount['issues'] ?></span></button>
            </div>
            <div class="sites-grid" id="sites">
                <?php foreach ($sites as $site) {
                    echo render_site_card($site);
                } ?>
            </div>
            <p class="grid-empty" id="sites-empty" hidden>No sites match this filter.</p>
        </section>
    </section>

    <section class="view" data-view="services">
        <section class="panel">
            <h2><i class="fa-solid fa-server"></i> Services</h2>
            <div class="services" id="services">
                <?php foreach ($services as $svc) {
                    echo render_service_card($svc);
                } ?>
            </div>
        </section>
    </section>

    <section class="view" data-view="databases">
        <section class="panel" id="db-panel">
            <div class="panel-head">
                <h2><i class="fa-solid fa-database"></i> Local databases <span class="count" id="db-count"><?= count($databases) ?></span></h2>
                <button class="icon-btn collapse-toggle" id="db-collapse" type="button" aria-label="Collapse local databases"><i class="fa-solid fa-chevron-up"></i></button>
            </div>
            <div class="collapse-wrap"><div class="db-list" id="databases"><?php echo render_db_list($databases); ?></div></div>
        </section>

        <div class="sync-banner">
            <i class="fa-solid fa-shield-halved"></i>
            <span><b>Cross-environment compare — read-only.</b> Connects with the credentials you enter (sent once, never stored) and only reads schema. <b>Production is never written by this tool yet (Phase 1).</b></span>
        </div>
        <div class="cols cols-even">
            <section class="panel">
                <div class="panel-head">
                    <h2><i class="fa-solid fa-layer-group"></i> Environments</h2>
                    <button id="grp-add" type="button" class="mini-btn"><i class="fa-solid fa-plus"></i> Add group</button>
                </div>
                <p class="cmp-note">Shareable &amp; secret-free. Group by project, then add an environment (local / staging / production) per row.</p>
                <div id="env-manager"></div>
                <div class="env-actions"><button id="env-save" type="button" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save</button><span id="env-status" class="muted"></span></div>
            </section>
            <section class="panel">
                <h2><i class="fa-solid fa-code-compare"></i> Compare schemas</h2>
                <div id="compare-form"></div>
            </section>
        </div>
        <section class="panel" id="compare-result-panel" hidden>
            <div class="panel-head"><h2><i class="fa-solid fa-list-check"></i> Schema differences</h2><span id="compare-summary"></span></div>
            <div id="compare-result"></div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2><i class="fa-solid fa-code-branch"></i> Schema migrations</h2></div>
            <p class="cmp-note">Versioned forward-DDL applied <b>local → staging → production</b>. Production requires a backup and every pending migration verified on staging first. Data is never touched.</p>
            <div id="mig-app"></div>
        </section>
    </section>

    <section class="view" data-view="logs">
        <section class="panel">
            <div class="panel-head">
                <h2><i class="fa-solid fa-file-lines"></i> Recent errors</h2>
                <div class="log-controls">
                    <select id="log-select" class="filter">
                        <?php foreach ($logs as $log) {
                            echo '<option value="' . esc($log['key']) . '">' . esc($log['name']) . '</option>';
                        } ?>
                    </select>
                    <select id="log-level" class="filter">
                        <option value="all">All levels</option>
                        <option value="error">Errors</option>
                        <option value="warn">Warnings</option>
                        <option value="notice">Notices</option>
                    </select>
                    <input type="search" id="log-filter" class="filter" placeholder="Filter lines…" aria-label="Filter log lines">
                </div>
            </div>
            <div class="log-view" id="log-view"><?php echo render_log_lines($firstLog); ?></div>
        </section>
    </section>

    <section class="view" data-view="system">
        <div class="cols">
            <section class="panel">
                <h2><i class="fa-solid fa-microchip"></i> System</h2>
                <div class="sys">
                    <div class="sys-row"><span>PHP</span><b><?= esc($system['php_version']) ?></b></div>
                    <div class="sys-row"><span>Memory limit</span><b><?= esc($system['memory_limit']) ?></b></div>
                    <div class="sys-row"><span>Upload max</span><b><?= esc($system['upload_max']) ?></b></div>
                    <div class="sys-row"><span>Extensions</span><b><?= esc((string) $system['ext_total']) ?> loaded</b></div>
                    <div class="sys-row"><span>OS</span><b><?= esc($system['os']) ?></b></div>
                    <div class="sys-row"><span>Server time</span><b id="server-time"><?= esc($system['server_time']) ?></b></div>
                    <div class="disk">
                        <div class="disk-head"><span>Disk <?= esc($system['drive']) ?></span>
                            <span id="disk-label"><?= esc(human_bytes($system['disk_used'])) ?> / <?= esc(human_bytes($system['disk_total'])) ?></span>
                        </div>
                        <div class="bar"><span id="disk-bar" style="width: <?= $diskPct ?>%"></span></div>
                    </div>
                </div>
            </section>

            <section class="panel">
                <h2><i class="fa-solid fa-bolt"></i> Quick actions</h2>
                <div class="actions">
                    <?php if ($system['has_phpmyadmin']) { ?>
                        <a class="action" href="http://localhost/phpmyadmin/" target="_blank" rel="noopener"><i class="fa-solid fa-database"></i><span>phpMyAdmin</span></a>
                    <?php } ?>
                    <a class="action" href="/xampp-pulse/phpinfo.php" target="_blank" rel="noopener"><i class="fa-solid fa-circle-info"></i><span>phpinfo()</span></a>
                    <a class="action" href="/dashboard/" target="_blank" rel="noopener"><i class="fa-solid fa-table-columns"></i><span>XAMPP dashboard</span></a>
                    <a class="action" href="https://www.php.net/manual/" target="_blank" rel="noopener"><i class="fa-solid fa-book"></i><span>PHP manual</span></a>
                    <a class="action" href="https://mariadb.com/kb/en/" target="_blank" rel="noopener"><i class="fa-solid fa-book-open"></i><span>MariaDB docs</span></a>
                    <a class="action" href="https://www.astole.me" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i><span>Author website</span></a>
                </div>
            </section>
        </div>
    </section>

    <footer class="foot">
        <span>XAMPP Pulse</span>
        <a href="https://astole.me" target="_blank" rel="noopener">© Aleksander Støle</a>
    </footer>
</div>

<div class="drawer-overlay" id="drawer-overlay"></div>
<aside class="drawer" id="drawer" aria-hidden="true">
    <div class="drawer-head">
        <h2 id="drawer-title">Site</h2>
        <button class="icon-btn" id="drawer-close" type="button" aria-label="Close details"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drawer-body" id="drawer-body"></div>
</aside>

<script>window.__PULSE_TOKEN__ = <?= json_encode(pulse_csrf_token()) ?>;</script>
<script>window.__SNAPSHOT__ = <?= pulse_json($snap) ?>;</script>
<script defer src="/xampp-pulse/assets/font-awesome/fontawesome.min.js"></script>
<script defer src="/xampp-pulse/assets/font-awesome/solid.min.js"></script>
<script defer src="/xampp-pulse/assets/js/dashboard.js"></script>
<script defer src="/xampp-pulse/assets/js/sync.js"></script>
<script defer src="/xampp-pulse/assets/js/migrations.js"></script>
<script defer src="/xampp-pulse/assets/js/sites-admin.js"></script>
</body>
</html>
