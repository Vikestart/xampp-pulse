'use strict';
(function () {
    const API = '/xampp-pulse/api.php';
    const SITES_API = '/xampp-pulse/sites-api.php';
    const HERO_TEXT = { ok: 'All systems operational', warn: 'Some sites are down', down: 'A service is down' };
    const TAB_KEYS = ['sites', 'services', 'databases', 'logs', 'system', 'ssh', 'mail', 'live'];
    const SPARK_MAX = 20;

    const $ = (id) => document.getElementById(id);
    const servicesEl = $('services');
    const sitesEl = $('sites');
    const countEl = $('site-count');
    const filterEl = $('site-filter');
    const updatedEl = $('updated');
    const themeBtn = $('theme-toggle');
    const refreshBtn = $('refresh');
    const notifyBtn = $('notify');
    const intervalSel = $('interval');
    const dbEl = $('databases');
    const logSelect = $('log-select');
    const logLevel = $('log-level');
    const logFilter = $('log-filter');
    const logView = $('log-view');
    const tabsEl = $('tabs');
    const drawer = $('drawer');
    const drawerOverlay = $('drawer-overlay');
    const drawerTitle = $('drawer-title');
    const drawerBody = $('drawer-body');

    let latest = window.__SNAPSHOT__ || {};
    let sitesByKey = {};
    const history = {};
    const prevStatus = {};
    let logsData = (latest.logs || []);
    let currentLog = logSelect ? logSelect.value : null;
    let lastUpdate = Date.now();
    let timer = null;
    let intervalMs = 5000;
    let notifyOn = false;
    let folderLaunch = window.__FOLDER_LAUNCH__ === true;
    let statusFilter = 'all';

    /* ---------- helpers ---------- */
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);
    function humanBytes(b) {
        const u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        let i = 0; b = Number(b) || 0;
        while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
        return (i === 0 || b >= 100 ? b.toFixed(0) : b.toFixed(1).replace(/\.0$/, '')) + ' ' + u[i];
    }
    const rtClass = (ms) => (ms < 200 ? 'rt-fast' : (ms < 700 ? 'rt-mid' : 'rt-slow'));
    function relTime(ts) {
        const d = Math.floor(Date.now() / 1000) - ts;
        if (d < 60) return 'just now';
        if (d < 3600) return Math.floor(d / 60) + 'm ago';
        if (d < 86400) return Math.floor(d / 3600) + 'h ago';
        if (d < 2592000) return Math.floor(d / 86400) + 'd ago';
        return new Date(ts * 1000).toISOString().slice(0, 10);
    }
    function logSev(l) {
        if (/Fatal error|Parse error|exception|:error\]/i.test(l)) return 'error';
        if (/Warning|Deprecated/i.test(l)) return 'warn';
        if (/Notice/i.test(l)) return 'notice';
        return '';
    }

    /* ---------- templates (mirror render.php) ---------- */
    const svcBtn = (svc, op, icon, label, cls) =>
        `<button class="svc-btn ${cls || ''}" type="button" data-svc="${esc(svc)}" data-op="${op}"><i class="fa-solid ${icon}"></i> ${label}</button>`;
    function serviceCard(key, s) {
        let ports = '';
        for (const [p, open] of Object.entries(s.ports || {})) ports += `<span class="port ${open ? 'on' : 'off'}">${esc(p)}</span>`;
        let ctrl = '';
        if (key === 'apache') ctrl = svcBtn('apache', 'restart', 'fa-rotate', 'Restart');
        else if (key === 'mysql') ctrl = s.up
            ? svcBtn('mysql', 'restart', 'fa-rotate', 'Restart') + svcBtn('mysql', 'stop', 'fa-stop', 'Stop', 'danger')
            : svcBtn('mysql', 'start', 'fa-play', 'Start', 'go');
        return `<article class="svc-card status-${s.up ? 'up' : 'down'}">`
            + `<div class="svc-top"><span class="dot"></span><h3>${esc(s.name)}</h3>`
            + `<span class="svc-state">${s.up ? 'Running' : 'Stopped'}</span></div>`
            + `<p class="svc-detail">${esc(s.detail)}</p><div class="ports">${ports}</div>`
            + (ctrl ? `<div class="svc-ctrl">${ctrl}</div>` : '')
            + '</article>';
    }

    function siteCard(s) {
        const key = s.domain || ('folder:' + s.folder);
        const name = s.domain || s.folder;
        const badge = s.configured ? `<span class="badge">${esc(s.code > 0 ? String(s.code) : 'down')}</span>` : `<span class="badge muted">no vhost</span>`;
        const title = s.configured
            ? `<a class="site-name" href="https://${esc(s.domain)}/" target="_blank" rel="noopener" title="${esc(s.domain)}">${esc(s.domain)}</a>`
            : `<span class="site-name" title="${esc(s.folder)}">${esc(s.folder)}</span>`;
        let certFlag = '';
        if (!s.has_cert) certFlag = `<span class="cert-flag warn" title="No certificate"><i class="fa-solid fa-lock-open"></i></span>`;
        else if (s.cert_days != null && s.cert_days < 30) certFlag = `<span class="cert-flag err" title="Certificate expires in ${s.cert_days} days"><i class="fa-solid fa-lock"></i></span>`;
        let issue = '';
        if (s.configured && s.errors > 0) issue = `<button type="button" class="issue err" data-log="${esc(s.slug)}" title="Errors in log — open Logs"><i class="fa-solid fa-circle-exclamation"></i> ${s.errors}</button>`;
        else if (s.configured && s.warnings > 0) issue = `<button type="button" class="issue warn" data-log="${esc(s.slug)}" title="Warnings in log — open Logs"><i class="fa-solid fa-triangle-exclamation"></i> ${s.warnings}</button>`;
        const chip = s.stack ? `<span class="chip">${esc(s.stack)}</span>` : '';
        const rt = (s.configured && s.status === 'up' && s.time_ms != null) ? `<span class="rt ${rtClass(s.time_ms)}"><i class="fa-solid fa-gauge-high"></i> ${s.time_ms} ms</span>` : '';
        const spark = s.configured ? `<div class="spark" data-spark="${esc(s.domain)}"></div>` : '';
        const meta = (chip || rt) ? `<div class="site-meta">${chip}${rt}</div>` : '';
        const issued = (s.errors > 0 || s.warnings > 0) ? '1' : '0';
        return `<article class="site-card status-${esc(s.status)}" data-key="${esc(key)}" data-state="${esc(s.status)}" data-issue="${issued}" data-search="${esc((name + ' ' + (s.stack || '')).toLowerCase())}" tabindex="0" role="button" aria-label="${esc(name)} — open details">`
            + `<div class="site-top"><span class="dot"></span>${title}<span class="site-right">${certFlag}${issue}${badge}</span></div>`
            + `${meta}${spark}</article>`;
    }

    /* Surface problems first: down → errors → warnings → healthy → no-vhost. */
    function siteRank(s) {
        if (!s.configured) return 4;
        if (s.status === 'down') return 0;
        if (s.errors > 0) return 1;
        if (s.warnings > 0) return 2;
        return 3;
    }
    function sortSites(sites) {
        return sites.slice().sort((a, b) =>
            siteRank(a) - siteRank(b) || (a.domain || a.folder).localeCompare(b.domain || b.folder));
    }
    function setChipCounts(c) {
        const sf = $('status-filter');
        if (!sf) return;
        sf.querySelectorAll('.chip-btn').forEach((b) => {
            const n = b.querySelector('.chip-n');
            if (n) n.textContent = c[b.dataset.filter] != null ? c[b.dataset.filter] : '';
        });
    }

    function dbList(databases) {
        if (!databases || !databases.length) return `<p class="empty">No user databases — or MySQL is not running.</p>`;
        return databases.map((db) => {
            const href = 'http://localhost/phpmyadmin/index.php?route=/database/structure&db=' + encodeURIComponent(db.name);
            return `<a class="db-row" href="${esc(href)}" target="_blank" rel="noopener">`
                + `<span class="db-name"><i class="fa-solid fa-table"></i> ${esc(db.name)}</span>`
                + `<span class="db-meta">${db.tables} tables · ${humanBytes(db.size)}</span></a>`;
        }).join('');
    }

    /* Always SPARK_MAX bars: real readings (left, oldest→newest) then faint grey
       placeholders that get replaced as history fills up. */
    function sparkBars(arr) {
        const data = arr.slice(-SPARK_MAX);
        const max = Math.max.apply(null, data.concat([1]));
        const real = data.map((v) => `<i style="height:${Math.max(18, Math.round(v / max * 100))}%"></i>`).join('');
        return real + '<i class="ph"></i>'.repeat(Math.max(0, SPARK_MAX - data.length));
    }
    function renderSparks() {
        sitesEl.querySelectorAll('.spark').forEach((el) => {
            const h = history[el.dataset.spark] || [];
            el.innerHTML = sparkBars(h);
            el.className = 'spark' + (h.length ? ' ' + rtClass(h[h.length - 1]) : '');
        });
    }

    function renderHero(sm) {
        const hero = $('hero');
        if (hero) hero.className = 'hero status-' + sm.overall;
        const t = $('hero-title'); if (t) t.textContent = HERO_TEXT[sm.overall] || '';
        const sub = $('hero-sub'); if (sub) sub.textContent = `${sm.sites_up} of ${sm.sites_total} sites up · ${sm.services_up}/${sm.services_total} services`;
        const set = (id, v) => { const e = $(id); if (e) e.textContent = v; };
        set('stat-up', sm.sites_up); set('stat-down', sm.sites_down);
        set('stat-svc', `${sm.services_up}/${sm.services_total}`); set('stat-disk', sm.disk_pct + '%');
    }

    /* ---------- logs ---------- */
    function renderLogs(logs) {
        logsData = logs || [];
        if (!logSelect) return;
        const keys = logsData.map((l) => l.key).join('|');
        if (logSelect.dataset.keys !== keys) {
            logSelect.innerHTML = logsData.map((l) => `<option value="${esc(l.key)}">${esc(l.name)}</option>`).join('');
            logSelect.dataset.keys = keys;
        }
        if (!logsData.some((l) => l.key === currentLog)) currentLog = logsData.length ? logsData[0].key : null;
        logSelect.value = currentLog || '';
        showLog();
    }
    function showLog() {
        if (!logView) return;
        const log = logsData.find((l) => l.key === currentLog);
        const lines = (log && log.lines) ? log.lines : [];
        const level = logLevel ? logLevel.value : 'all';
        const q = (logFilter ? logFilter.value : '').trim().toLowerCase();
        const out = lines.filter((l) => {
            if (q && !l.toLowerCase().includes(q)) return false;
            if (level !== 'all' && logSev(l) !== level) return false;
            return true;
        });
        logView.innerHTML = out.length
            ? out.map((l) => `<div class="log-line ${logSev(l)}">${esc(l)}</div>`).join('')
            : '<div class="log-line empty">No matching entries.</div>';
    }
    function gotoLog(key) {
        document.documentElement.dataset.tab = 'logs';
        try { localStorage.setItem('dash-tab', 'logs'); } catch (e) { /* ignore */ }
        currentLog = key;
        if (logSelect) logSelect.value = key;
        showLog();
    }

    /* ---------- drawer ---------- */
    function drawerContent(s) {
        const tags = [];
        if (s.stack) tags.push(`<span class="chip">${esc(s.stack)}</span>`);
        if (s.configured) tags.push(`<span class="tag ${s.status === 'up' ? 'ok' : 'danger'}">${s.status === 'up' ? 'up · ' + s.code : 'down'}</span>`);
        if (s.has_cert) tags.push(`<span class="tag ok"><i class="fa-solid fa-lock"></i> ${s.cert_expires ? esc(s.cert_expires) : 'cert'}${s.cert_days != null ? ' · ' + s.cert_days + 'd' : ''}</span>`);
        if (s.configured && s.time_ms != null) tags.push(`<span class="tag ${rtClass(s.time_ms)}">${s.time_ms} ms</span>`);
        const rows = [
            ['DocumentRoot', `htdocs/${esc(s.folder)}`],
            ['Full path', esc(s.docroot)],
            ['Size', s.size == null ? 'calculating…' : (s.size_capped ? '≥ ' : '') + humanBytes(s.size)],
            ['Modified', s.modified != null ? esc(relTime(s.modified)) : '—'],
            ['Log slug', esc(s.slug)],
        ].map(([k, v]) => `<div class="sys-row"><span>${k}</span><b>${v}</b></div>`).join('');
        const h = history[s.domain] || [];
        const big = `<div class="spark big ${h.length ? rtClass(h[h.length - 1]) : ''}">${sparkBars(h)}</div>`;
        let stats = '';
        if (h.length) {
            const avg = Math.round(h.reduce((a, b) => a + b, 0) / h.length);
            stats = `<div class="rt-stats"><span>min <b>${Math.min.apply(null, h)}</b> ms</span><span>avg <b>${avg}</b> ms</span><span>max <b>${Math.max.apply(null, h)}</b> ms</span></div>`;
        }
        const log = (latest.logs || []).find((l) => l.key === s.slug);
        const lines = (log && log.lines) ? log.lines : [];
        const logHtml = lines.length ? lines.map((l) => `<div class="log-line ${logSev(l)}">${esc(l)}</div>`).join('') : '<div class="log-line empty">No recent entries.</div>';
        let errBadge = '';
        if (s.errors > 0) errBadge = `<span class="err-count err">${s.errors}</span>`;
        else if (s.warnings > 0) errBadge = `<span class="err-count warn">${s.warnings}</span>`;
        const actions = [];
        if (s.configured) actions.push(`<a class="action" href="https://${esc(s.domain)}/" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i><span>Open site</span></a>`);
        if (s.docroot_exists) actions.push(`<a class="action" href="vscode://file/${esc(s.docroot)}"><i class="fa-solid fa-code"></i><span>Open in VS Code</span></a>`);
        if (s.docroot_exists) actions.push(`<button class="action open-folder" type="button" data-path="${esc(s.docroot)}"><i class="fa-solid fa-folder-open"></i><span>Open folder</span></button>`);
        if (s.docroot_exists) actions.push(`<button class="action site-env" type="button" data-folder="${esc(s.folder)}"><i class="fa-solid fa-file-code"></i><span>Edit .env</span></button>`);
        if (s.docroot_exists) actions.push(`<button class="action site-git" type="button" data-folder="${esc(s.folder)}"><i class="fa-solid fa-code-branch"></i><span>Git</span></button>`);
        if (s.docroot_exists) actions.push(`<button class="action site-tasks" type="button" data-folder="${esc(s.folder)}"><i class="fa-solid fa-play"></i><span>Tasks</span></button>`);
        if (s.configured) actions.push(`<button class="action site-rename" data-domain="${esc(s.domain)}" data-folder="${esc(s.folder)}"><i class="fa-solid fa-pen"></i><span>Rename</span></button>`);
        if (s.configured) actions.push(`<button class="action danger site-remove" data-domain="${esc(s.domain)}" data-folder="${esc(s.folder)}"><i class="fa-solid fa-trash"></i><span>Remove</span></button>`);
        return `<div class="drawer-tags">${tags.join('')}</div>`
            + `<h3 class="drawer-h">Actions</h3><div class="actions">${actions.join('')}</div>`
            + `<div class="sys">${rows}</div>`
            + `<h3 class="drawer-h">Response time</h3>${big}${stats}`
            + `<details class="drawer-errors"><summary class="drawer-summary"><span>Recent errors</span>${errBadge}<i class="fa-solid fa-chevron-down chev"></i></summary><div class="log-view">${logHtml}</div></details>`;
    }
    function openDrawer(key) {
        const s = sitesByKey[key];
        if (!s) return;
        drawerTitle.textContent = s.domain || s.folder;
        drawerBody.innerHTML = drawerContent(s);
        drawer.classList.add('open'); drawerOverlay.classList.add('open'); drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('drawer-open');
    }
    function closeDrawer() {
        drawer.classList.remove('open'); drawerOverlay.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('drawer-open');
    }

    /* ---------- notifications ---------- */
    function notify(title, body) {
        try { if (window.Notification && Notification.permission === 'granted') new Notification(title, { body, icon: '/xampp-pulse/assets/img/icon.svg' }); } catch (e) { /* ignore */ }
    }
    function detectChanges(sites) {
        sites.forEach((s) => {
            if (!s.configured) return;
            const prev = prevStatus[s.domain];
            if (prev && prev !== s.status) {
                if (notifyOn && s.status === 'down') notify(`${s.domain} is DOWN`, 'No response from the site.');
                else if (notifyOn && s.status === 'up' && prev === 'down') notify(`${s.domain} recovered`, 'The site is responding again.');
            }
            prevStatus[s.domain] = s.status;
        });
    }
    function setBellIcon() {
        notifyBtn.innerHTML = `<i class="fa-solid fa-bell${notifyOn ? '' : '-slash'}"></i>`;
        notifyBtn.classList.toggle('on', notifyOn);
    }
    function clearNotifyHint() { document.querySelectorAll('.notify-hint').forEach((n) => n.remove()); }
    // Guide the user when the browser doesn't show the loud Allow/Block modal — either it
    // demoted the request to the quiet address-bar bell, or notifications are already blocked.
    function notifyHint(kind) {
        clearNotifyHint();
        const denied = kind === 'denied';
        const el = document.createElement('div');
        el.className = 'notify-hint';
        el.setAttribute('role', 'status');
        el.innerHTML = denied
            ? '<i class="fa-solid fa-bell-slash"></i><p>Notifications are <b>blocked</b> for this site. Open the site&rsquo;s permissions <b>from the address bar → Notifications → Allow</b>, then click the bell again.</p><i class="fa-solid fa-xmark nh-x"></i>'
            : '<i class="fa-solid fa-bell"></i><p>Edge may ask quietly. If no <b>Allow</b> popup appears, click the <b>bell icon in the address bar</b> and choose <b>Allow</b>.</p><i class="fa-solid fa-xmark nh-x"></i>';
        document.body.appendChild(el);
        const kill = () => { el.classList.add('out'); setTimeout(() => el.remove(), 320); };
        el.addEventListener('click', kill);
        setTimeout(kill, denied ? 11000 : 9000);
    }

    /* ---------- apply snapshot ---------- */
    function apply(data) {
        latest = data;
        if (data.summary) renderHero(data.summary);
        if (data.services) servicesEl.innerHTML = Object.entries(data.services).map(([k, s]) => serviceCard(k, s)).join('');
        if (Array.isArray(data.sites)) {
            sitesByKey = {};
            const counts = { all: 0, up: 0, down: 0, issues: 0 };
            data.sites.forEach((s) => {
                sitesByKey[s.domain || ('folder:' + s.folder)] = s;
                if (s.configured && s.status === 'up' && s.time_ms != null) {
                    (history[s.domain] = history[s.domain] || []).push(s.time_ms);
                    if (history[s.domain].length > SPARK_MAX) history[s.domain].shift();
                }
                counts.all++;
                if (s.configured && s.status === 'up') counts.up++;
                if (s.configured && s.status === 'down') counts.down++;
                if (s.configured && (s.status === 'down' || s.errors > 0 || s.warnings > 0)) counts.issues++;
            });
            sitesEl.innerHTML = sortSites(data.sites).map(siteCard).join('');
            countEl.textContent = String(data.sites.filter((s) => s.configured).length);
            setChipCounts(counts);
            applyFilter();
            renderSparks();
            detectChanges(data.sites);
        }
        if (data.databases && dbEl) {
            dbEl.innerHTML = dbList(data.databases);
            const dc = $('db-count'); if (dc) dc.textContent = String(data.databases.length);
        }
        if (data.logs) renderLogs(data.logs);
        if (data.system) {
            const sys = data.system;
            const st = $('server-time'); if (st) st.textContent = sys.server_time;
            const dl = $('disk-label'); if (dl) dl.textContent = humanBytes(sys.disk_used) + ' / ' + humanBytes(sys.disk_total);
            const db = $('disk-bar'); if (db && sys.disk_total > 0) db.style.width = Math.round(sys.disk_used / sys.disk_total * 100) + '%';
        }
        lastUpdate = Date.now();
    }
    function statusOk(card) {
        if (statusFilter === 'all') return true;
        if (statusFilter === 'issues') return card.dataset.state === 'down' || card.dataset.issue === '1';
        return card.dataset.state === statusFilter;
    }
    function applyFilter() {
        const q = (filterEl.value || '').trim().toLowerCase();
        let visible = 0;
        sitesEl.querySelectorAll('.site-card').forEach((card) => {
            const show = (q === '' || card.dataset.search.includes(q)) && statusOk(card);
            card.classList.toggle('hide', !show);
            if (show) visible++;
        });
        const empty = $('sites-empty');
        if (empty) {
            empty.hidden = visible !== 0;
            if (!visible) empty.textContent = q ? `No sites match “${q}”.` : 'No sites match this filter.';
        }
    }

    /* ---------- polling ---------- */
    let polling = false;
    async function poll() {
        if (polling) return;
        polling = true;
        refreshBtn.classList.add('busy');
        try {
            const res = await fetch(API, { cache: 'no-store' });
            if (!res.ok) throw new Error(String(res.status));
            apply(await res.json());
        } catch (e) {
            updatedEl.textContent = 'offline';
        } finally {
            polling = false;
            refreshBtn.classList.remove('busy');
        }
    }
    /* Don't let an auto-refresh re-render the grid (dropping focus) mid-interaction.
       The "updated Ns ago" indicator keeps ticking so the hold is visible. */
    function isInteracting() {
        return document.body.classList.contains('drawer-open') || sitesEl.contains(document.activeElement);
    }
    function startTimer() {
        if (timer) clearInterval(timer);
        if (intervalMs > 0) timer = setInterval(() => { if (!isInteracting()) poll(); }, intervalMs);
    }
    function tickUpdated() {
        if (intervalMs === 0) { updatedEl.textContent = 'paused'; return; }
        const secs = Math.round((Date.now() - lastUpdate) / 1000);
        updatedEl.textContent = secs < 3 ? 'live' : `updated ${secs}s ago`;
    }

    /* ---------- theme ---------- */
    function setThemeIcon() {
        themeBtn.innerHTML = `<i class="fa-solid fa-${document.documentElement.dataset.theme === 'dark' ? 'sun' : 'moon'}"></i>`;
    }
    function toggleTheme() {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { localStorage.setItem('dash-theme', next); } catch (e) { /* ignore */ }
        setThemeIcon();
    }

    /* ---------- wire up ---------- */
    themeBtn.addEventListener('click', toggleTheme);
    refreshBtn.addEventListener('click', poll);
    filterEl.addEventListener('input', applyFilter);
    const statusFilterEl = $('status-filter');
    if (statusFilterEl) statusFilterEl.addEventListener('click', (e) => {
        const btn = e.target.closest('.chip-btn');
        if (!btn) return;
        statusFilter = btn.dataset.filter;
        statusFilterEl.querySelectorAll('.chip-btn').forEach((b) => b.classList.toggle('is-active', b === btn));
        try { localStorage.setItem('dash-status-filter', statusFilter); } catch (err) { /* ignore */ }
        applyFilter();
    });
    /* Keep the tab bar docked just below the (already sticky) topbar. */
    function syncStickyTabs() {
        if (!tabsEl) return;
        const tb = document.querySelector('.topbar');
        tabsEl.style.top = (tb ? tb.offsetHeight : 0) + 'px';
    }
    window.addEventListener('resize', syncStickyTabs);
    const dbCollapse = $('db-collapse');
    if (dbCollapse) {
        dbCollapse.addEventListener('click', () => {
            const collapsed = document.documentElement.dataset.dbcollapsed !== '1';
            document.documentElement.dataset.dbcollapsed = collapsed ? '1' : '';
            try { localStorage.setItem('dash-db-collapsed', collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
        });
    }
    if (logSelect) logSelect.addEventListener('change', () => { currentLog = logSelect.value; showLog(); });
    if (logLevel) logLevel.addEventListener('change', showLog);
    if (logFilter) logFilter.addEventListener('input', showLog);
    if (tabsEl) tabsEl.addEventListener('click', (e) => {
        const btn = e.target.closest('.tab');
        if (!btn) return;
        document.documentElement.dataset.tab = btn.dataset.tab;
        try { localStorage.setItem('dash-tab', btn.dataset.tab); } catch (err) { /* ignore */ }
    });

    sitesEl.addEventListener('click', (e) => {
        const issue = e.target.closest('.issue');
        if (issue) { e.preventDefault(); gotoLog(issue.dataset.log); return; }
        if (e.target.closest('a')) return;
        const card = e.target.closest('.site-card');
        if (card && card.dataset.key) openDrawer(card.dataset.key);
    });
    sitesEl.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if (e.target.closest('a, button')) return;
        const card = e.target.closest('.site-card');
        if (card && card.dataset.key) { e.preventDefault(); openDrawer(card.dataset.key); }
    });
    servicesEl.addEventListener('click', async (e) => {
        const btn = e.target.closest('.svc-btn');
        if (!btn) return;
        const svc = btn.dataset.svc;
        const op = btn.dataset.op;
        if (op === 'stop' && !window.confirm(`Stop ${svc}? Local sites using it will go down until you start it again.`)) return;
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = 'Working…';
        try {
            const r = await window.pulsePost(SITES_API, { action: 'service', service: svc, op });
            if (!r.ok) { btn.disabled = false; btn.innerHTML = orig; window.alert(r.error || 'Service action failed.'); return; }
            setTimeout(poll, svc === 'apache' ? 2800 : 1500);
        } catch (x) {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });
    drawerOverlay.addEventListener('click', closeDrawer);
    $('drawer-close').addEventListener('click', closeDrawer);

    // Open the site folder in Explorer via the pulsefolder:// handler (crosses session-0,
    // like the SSH terminal button). First use registers the handler (auth-gated); if that's
    // declined or unavailable, fall back to copying the path so it can be pasted into Explorer.
    drawerBody.addEventListener('click', async (e) => {
        const of = e.target.closest('.open-folder');
        if (!of) return;
        const path = of.dataset.path;
        const span = of.querySelector('span');
        const orig = span.textContent;
        const flash = (txt, ms) => { span.textContent = txt; of.classList.add('ok'); setTimeout(() => { span.textContent = orig; of.classList.remove('ok'); }, ms || 1400); };
        const launch = () => { const a = document.createElement('a'); a.href = 'pulsefolder:' + encodeURIComponent(path); a.click(); };
        const copyFallback = () => {
            if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(path).then(() => flash('Path copied', 1800)).catch(() => flash('Failed'));
            else flash('Unavailable');
        };
        if (folderLaunch) { launch(); flash('Opening…'); return; }
        of.disabled = true;
        span.textContent = 'Enabling…';
        try {
            const r = await window.pulsePost('/xampp-pulse/sites-api.php', { action: 'open_folder_enable' });
            if (r && r.ok) { folderLaunch = true; launch(); flash('Opening…'); }
            else if (r && r.locked) { span.textContent = orig; }
            else copyFallback();
        } catch (x) { copyFallback(); }
        finally { of.disabled = false; }
    });

    notifyBtn.addEventListener('click', async () => {
        if (!notifyOn) {
            if (window.Notification && Notification.permission !== 'granted') {
                let settled = false;
                // On low-engagement origins Edge/Chrome demote the prompt to the quiet
                // address-bar bell and leave the promise pending — nudge the user to it.
                const hintTimer = setTimeout(() => {
                    if (!settled && Notification.permission !== 'granted') notifyHint('default');
                }, 1500);
                let p;
                try { p = await Notification.requestPermission(); } catch (e) { p = Notification.permission; }
                settled = true;
                clearTimeout(hintTimer);
                if (p !== 'granted') { notifyHint(p === 'denied' ? 'denied' : 'default'); return; }
                clearNotifyHint();
            }
            notifyOn = true;
        } else { notifyOn = false; }
        try { localStorage.setItem('dash-notify', notifyOn ? '1' : '0'); } catch (e) { /* ignore */ }
        setBellIcon();
    });

    intervalSel.addEventListener('change', () => {
        intervalMs = parseInt(intervalSel.value, 10) || 0;
        try { localStorage.setItem('dash-interval', String(intervalMs)); } catch (e) { /* ignore */ }
        startTimer();
        tickUpdated();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { closeDrawer(); return; }
        const tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'select' || tag === 'textarea') return;
        if (e.key >= '1' && e.key <= '8') {
            const k = TAB_KEYS[+e.key - 1];
            document.documentElement.dataset.tab = k;
            try { localStorage.setItem('dash-tab', k); } catch (x) { /* ignore */ }
        } else if (e.key === '/') {
            e.preventDefault();
            document.documentElement.dataset.tab = 'sites';
            filterEl.focus();
        } else if (e.key === 'r') { poll(); }
    });

    /* Credential blocks are <form>s (so the browser treats the password fields
       as real password fields) but have no server action — swallow submit. */
    document.addEventListener('submit', (e) => {
        if (e.target.matches('form.cmp-creds')) e.preventDefault();
    });

    /* ---------- init ---------- */
    try {
        const savedInterval = localStorage.getItem('dash-interval');
        if (savedInterval !== null) intervalMs = parseInt(savedInterval, 10) || 0;
        intervalSel.value = String(intervalMs);
        notifyOn = localStorage.getItem('dash-notify') === '1' && window.Notification && Notification.permission === 'granted';
    } catch (e) { /* ignore */ }
    try {
        const sf = localStorage.getItem('dash-status-filter');
        if (sf && statusFilterEl) {
            statusFilter = sf;
            statusFilterEl.querySelectorAll('.chip-btn').forEach((b) => b.classList.toggle('is-active', b.dataset.filter === sf));
        }
    } catch (e) { /* ignore */ }
    setThemeIcon();
    setBellIcon();
    syncStickyTabs();
    window.pulseRefresh = poll;
    window.pulseCloseDrawer = closeDrawer;
    window.pulseOpenDrawer = openDrawer;
    window.pulseSiteList = () => Object.entries(sitesByKey).map(([key, s]) => ({ key, name: s.domain || s.folder, up: s.status === 'up' }));
    if (window.__SNAPSHOT__) apply(window.__SNAPSHOT__);
    poll();
    startTimer();
    setInterval(tickUpdated, 1000);
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => { /* ignore */ });
})();
