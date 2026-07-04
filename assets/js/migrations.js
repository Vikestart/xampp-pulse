'use strict';
(function () {
    const API = '/xampp-pulse/sync-api.php';
    const $ = (id) => document.getElementById(id);
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);
    const app = $('mig-app');
    if (!app) return;

    let environments = [];
    let groupsMap = new Map();
    let envById = {};

    async function post(p) {
        return window.pulsePost(API, p);
    }
    function indexEnvs() {
        groupsMap = new Map(); envById = {};
        environments.forEach((e) => { envById[e.id] = e; if (!groupsMap.has(e.group)) groupsMap.set(e.group, []); groupsMap.get(e.group).push(e); });
    }
    const groupEnvOpts = (g) => (groupsMap.get(g) || []).map((e) => `<option value="${esc(e.id)}">${esc(e.role)}${e.db ? ' (' + esc(e.db) + ')' : ''}</option>`).join('');
    const curGroup = () => ($('mig-group') ? $('mig-group').value : '');

    function render() {
        indexEnvs();
        const groups = [...groupsMap.keys()];
        if (!groups.length) { app.innerHTML = '<p class="empty">Add environment groups above first.</p>'; return; }
        app.innerHTML = `<div class="cmp-group"><label>Project / group</label><select id="mig-group">${groups.map((g) => `<option value="${esc(g)}">${esc(g)}</option>`).join('')}</select></div>`
            + `<div class="cols cols-even">`
            + `<div><h3 class="drawer-h">Migration files</h3><div id="mig-files"></div>`
            + `<h3 class="drawer-h">New migration</h3>`
            + `<input id="mig-title" class="mig-input" placeholder="title (e.g. add phone to users)" autocomplete="off">`
            + `<textarea id="mig-sql" class="mig-sql" placeholder="-- forward DDL only, e.g.\nALTER TABLE users ADD COLUMN phone VARCHAR(32) NULL;"></textarea>`
            + `<div class="env-actions"><button id="mig-save" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save migration</button><span id="mig-save-status" class="muted"></span></div></div>`
            + `<div><h3 class="drawer-h">Apply pending</h3>`
            + `<label class="mig-label">Target</label><select id="mig-target"></select>`
            + `<form class="cmp-creds"><input id="mig-tuser" value="root" placeholder="user" autocomplete="off"><input id="mig-tpass" type="password" placeholder="password" autocomplete="off"></form>`
            + `<div id="mig-staging-box" hidden><label class="mig-label">Staging (verify against — required for production)</label><select id="mig-staging"></select><form class="cmp-creds"><input id="mig-suser" value="root" placeholder="user"><input id="mig-spass" type="password" placeholder="password"></form></div>`
            + `<div class="env-actions"><button id="mig-check" class="mini-btn"><i class="fa-solid fa-list-check"></i> Check status</button></div>`
            + `<div id="mig-status"></div>`
            + `<div class="apply-row"><input id="mig-confirm" placeholder="type target db to apply" autocomplete="off"><button id="mig-apply" class="btn-danger"><i class="fa-solid fa-arrow-up-from-bracket"></i> Apply pending</button></div>`
            + `<div id="mig-report"></div></div></div>`;
        $('mig-group').addEventListener('change', onGroup);
        $('mig-save').addEventListener('click', saveMig);
        $('mig-check').addEventListener('click', checkStatus);
        $('mig-apply').addEventListener('click', applyMig);
        $('mig-target').addEventListener('change', onTargetChange);
        onGroup();
    }

    function onGroup() {
        const g = curGroup();
        $('mig-target').innerHTML = groupEnvOpts(g);
        $('mig-staging').innerHTML = (groupsMap.get(g) || []).filter((e) => e.role === 'staging').map((e) => `<option value="${esc(e.id)}">${esc(e.role)}${e.db ? ' (' + esc(e.db) + ')' : ''}</option>`).join('');
        onTargetChange();
        loadFiles();
    }
    function onTargetChange() {
        const env = envById[$('mig-target').value];
        $('mig-staging-box').hidden = !(env && env.role === 'production');
        $('mig-status').innerHTML = ''; $('mig-report').innerHTML = '';
    }
    async function loadFiles() {
        const d = await post({ action: 'mig_list', group: curGroup() });
        const files = d.files || [];
        $('mig-files').innerHTML = files.length ? files.map((f) => `<div class="mig-file"><i class="fa-solid fa-file-code"></i> ${esc(f)}</div>`).join('') : '<p class="cmp-note">No migrations yet.</p>';
    }
    async function saveMig() {
        const st = $('mig-save-status'); st.textContent = 'Saving…';
        const d = await post({ action: 'mig_save', group: curGroup(), title: $('mig-title').value, sql: $('mig-sql').value });
        if (d.ok) { $('mig-title').value = ''; $('mig-sql').value = ''; st.textContent = 'Saved ' + d.file; loadFiles(); setTimeout(() => { st.textContent = ''; }, 2500); }
        else st.textContent = d.error || 'Save failed';
    }
    async function checkStatus() {
        const status = $('mig-status'); status.innerHTML = '<p class="cmp-note">Checking…</p>';
        const d = await post({ action: 'mig_status', group: curGroup(), env: $('mig-target').value, user: $('mig-tuser').value, pass: $('mig-tpass').value });
        if (!d.ok) { status.innerHTML = `<div class="diff-error">${esc(d.error)}</div>`; return; }
        status.innerHTML = `<div class="mig-status-line"><span class="pill ok">${d.applied.length} applied</span><span class="pill warn">${d.pending.length} pending</span></div>`
            + (d.pending.length ? '<div class="cmp-note">Pending: ' + d.pending.map(esc).join(', ') + '</div>' : '');
    }
    async function applyMig() {
        const env = envById[$('mig-target').value];
        const btn = $('mig-apply'), rep = $('mig-report');
        btn.disabled = true; btn.classList.add('busy');
        rep.innerHTML = '<p class="cmp-note">Backing up &amp; applying…</p>';
        const p = { action: 'mig_apply', group: curGroup(), target: $('mig-target').value, target_user: $('mig-tuser').value, target_pass: $('mig-tpass').value, confirm: $('mig-confirm').value };
        if (env && env.role === 'production') { p.staging = $('mig-staging').value; p.staging_user = $('mig-suser').value; p.staging_pass = $('mig-spass').value; }
        try {
            const r = await post(p);
            if (!r.ok) {
                rep.innerHTML = `<div class="diff-error"><b>${r.failed ? 'Failed at ' + esc(r.failed) : 'Blocked'}.</b> ${esc(r.error || '')}${r.backup ? '<br><small>Backup: ' + esc(r.backup) + '</small>' : ''}</div>`;
            } else {
                const list = r.applied && r.applied.length ? ' (' + r.applied.map(esc).join(', ') + ')' : '';
                rep.innerHTML = `<div class="sync-ok"><i class="fa-solid fa-check"></i> ${esc(r.message || ('Applied ' + r.applied.length + ' migration' + (r.applied.length === 1 ? '' : 's')))}${list}.${r.backup ? '<br><small>Backup: ' + esc(r.backup) + '</small>' : ''}</div>`;
                checkStatus(); loadFiles();
            }
        } catch (e) { rep.innerHTML = `<div class="diff-error">${esc(e.message || e)}</div>`; }
        finally { btn.disabled = false; btn.classList.remove('busy'); }
    }

    /* called by sync.js after a compare → prefill a draft migration */
    window.migFill = function (group, sql) {
        const gsel = $('mig-group');
        if (gsel && group && [...gsel.options].some((o) => o.value === group)) { gsel.value = group; onGroup(); }
        const ta = $('mig-sql');
        if (ta) { ta.value = sql; ta.scrollIntoView({ behavior: 'smooth', block: 'center' }); if ($('mig-title')) $('mig-title').focus(); }
    };

    (async function init() {
        const d = await post({ action: 'envs' });
        environments = d.environments || [];
        render();
    })();
})();
