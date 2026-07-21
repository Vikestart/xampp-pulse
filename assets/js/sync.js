'use strict';
(function () {
    const API = '/xampp-pulse/sync-api.php';
    const ROLES = ['local', 'staging', 'production'];

    const $ = (id) => document.getElementById(id);
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);

    const envMgr = $('env-manager');
    const cmpForm = $('compare-form');
    const resultPanel = $('compare-result-panel');
    const resultEl = $('compare-result');
    const summaryEl = $('compare-summary');
    if (!envMgr) return;

    let environments = [];
    let groupsMap = new Map();
    let envById = {};
    let lastCmp = null;

    async function post(params) {
        return window.pulsePost(API, params);
    }
    function indexEnvs() {
        groupsMap = new Map();
        envById = {};
        environments.forEach((e) => {
            envById[e.id] = e;
            if (!groupsMap.has(e.group)) groupsMap.set(e.group, []);
            groupsMap.get(e.group).push(e);
        });
    }

    /* ---------- environment groups (repeater) ---------- */
    function envRow(e) {
        const roles = ROLES.map((r) => `<option value="${r}"${e.role === r ? ' selected' : ''}>${r}</option>`).join('');
        return `<div class="env-row"><select class="f-role">${roles}</select>`
            + `<input class="f-host" value="${esc(e.host || '')}" placeholder="host / Tailscale IP">`
            + `<input class="f-port" value="${esc(e.port || 3306)}" placeholder="port">`
            + `<input class="f-db" value="${esc(e.db || '')}" placeholder="database">`
            + `<button type="button" class="env-del icon-btn" title="Remove environment"><i class="fa-solid fa-trash"></i></button></div>`;
    }
    function groupBox(g) {
        return `<div class="env-group"><div class="env-group-head">`
            + `<input class="g-name" value="${esc(g.name || '')}" placeholder="Project / group name">`
            + `<button type="button" class="grp-add-env mini-btn"><i class="fa-solid fa-plus"></i> Env</button>`
            + `<button type="button" class="grp-del icon-btn" title="Remove group"><i class="fa-solid fa-trash"></i></button></div>`
            + `<div class="env-rows">${(g.environments || []).map(envRow).join('')}</div></div>`;
    }
    function renderGroups(groups) {
        envMgr.innerHTML = (groups && groups.length) ? groups.map(groupBox).join('') : '<p class="empty">No groups yet — add one.</p>';
    }
    function collectGroups() {
        return [...envMgr.querySelectorAll('.env-group')].map((box) => ({
            name: box.querySelector('.g-name').value,
            environments: [...box.querySelectorAll('.env-row')].map((row) => ({
                role: row.querySelector('.f-role').value,
                host: row.querySelector('.f-host').value,
                port: parseInt(row.querySelector('.f-port').value, 10) || 3306,
                db: row.querySelector('.f-db').value,
            })),
        }));
    }

    /* ---------- compare form (group selector → source/target) ---------- */
    function envOptionsForGroup(gname) {
        return (groupsMap.get(gname) || []).map((e) => `<option value="${esc(e.id)}">${esc(e.role)}${e.db ? ' (' + esc(e.db) + ')' : ''}</option>`).join('');
    }
    function renderCompareForm() {
        indexEnvs();
        if (!environments.length) { cmpForm.innerHTML = '<p class="empty">Add environments and Save, then compare.</p>'; return; }
        const groups = [...groupsMap.keys()];
        cmpForm.innerHTML = `<div class="cmp-group"><label>Project / group</label><select id="cmp-group">${groups.map((g) => `<option value="${esc(g)}">${esc(g)}</option>`).join('')}</select></div>`
            + `<div class="cmp-grid">`
            + `<div class="cmp-side"><label>Source (read)</label><select id="cmp-source"></select><form class="cmp-creds"><input id="cmp-source-user" value="root" placeholder="user" autocomplete="off"><input id="cmp-source-pass" type="password" placeholder="password" autocomplete="off"></form></div>`
            + `<div class="cmp-side"><label>Target (read)</label><select id="cmp-target"></select><form class="cmp-creds"><input id="cmp-target-user" value="root" placeholder="user" autocomplete="off"><input id="cmp-target-pass" type="password" placeholder="password" autocomplete="off"></form></div>`
            + `</div>`
            + `<button id="cmp-run" class="btn-primary"><i class="fa-solid fa-code-compare"></i> Compare schemas</button>`
            + `<p class="cmp-note">Read-only. Credentials are sent once and never stored.</p>`;
        const grp = $('cmp-group');
        function fill() {
            const opts = envOptionsForGroup(grp.value);
            $('cmp-source').innerHTML = opts;
            $('cmp-target').innerHTML = opts;
            const list = groupsMap.get(grp.value) || [];
            if (list[1]) $('cmp-target').selectedIndex = 1;
        }
        grp.addEventListener('change', fill);
        fill();
        $('cmp-run').addEventListener('click', runCompare);
    }

    /* ---------- compare results ---------- */
    function rowsLabel(n) { return n == null ? '' : ' · ≈' + n.toLocaleString() + ' rows'; }
    function tableBlock(t, s, g) {
        const dz = t.destructive ? '<span class="dz" title="Would drop/alter existing structure">⚠</span>' : '';
        if (t.status === 'only_source') return `<div class="diff-row add"><span class="diff-sign">＋</span><b>${esc(t.name)}</b><span class="diff-meta">in ${esc(s)} only${rowsLabel(t.rows_source)}</span></div>`;
        if (t.status === 'only_target') return `<div class="diff-row del"><span class="diff-sign">－</span><b>${esc(t.name)}</b>${dz}<span class="diff-meta">in ${esc(g)} only${rowsLabel(t.rows_target)}</span></div>`;
        const sign = (st) => st === 'only_source' ? '＋' : st === 'only_target' ? '－' : '~';
        const cols = t.cols.map((c) => `<div class="diff-sub ${c.status}"><span class="diff-sign">${sign(c.status)}</span>${esc(c.name)} <code>${esc(c.detail)}</code></div>`).join('');
        const idx = t.idx.map((c) => `<div class="diff-sub ${c.status}"><span class="diff-sign">${sign(c.status)}</span><i class="fa-solid fa-key"></i> ${esc(c.name)} <code>${esc(c.detail)}</code></div>`).join('');
        return `<div class="diff-row chg"><div class="diff-row-head"><span class="diff-sign">~</span><b>${esc(t.name)}</b>${dz}<span class="diff-meta">${t.cols.length} column · ${t.idx.length} index diffs</span></div>${cols}${idx}</div>`;
    }
    function renderResult(d) {
        resultPanel.hidden = false;
        $('plan-area') && ($('plan-area').innerHTML = '');
        if (!d.ok) {
            const err = (d.source && d.source.error) || (d.target && d.target.error) || d.error || 'Compare failed.';
            const which = (d.source && d.source.error) ? d.source.label : (d.target && d.target.error) ? d.target.label : '';
            summaryEl.innerHTML = '';
            resultEl.innerHTML = `<div class="diff-error"><i class="fa-solid fa-triangle-exclamation"></i> ${esc(which ? which + ': ' : '')}${esc(err)}</div>`;
            return;
        }
        const n = d.summary.differences;
        summaryEl.innerHTML = n === 0 ? `<span class="pill ok"><i class="fa-solid fa-check"></i> in sync</span>` : `<span class="pill warn">${n} difference${n === 1 ? '' : 's'}</span>`;
        const s = d.source, t = d.target;
        const head = `<div class="cmp-heads"><span><b>${esc(s.label)}</b> <small>${esc(s.db)} · ${s.tables} tables</small></span><i class="fa-solid fa-arrow-right-arrow-left"></i><span><b>${esc(t.label)}</b> <small>${esc(t.db)} · ${t.tables} tables</small></span></div>`;
        const changed = d.diff.tables.filter((x) => x.status !== 'same');
        const same = d.diff.tables.length - changed.length;
        const body = changed.length ? changed.map((x) => tableBlock(x, s.label, t.label)).join('') : '<div class="diff-row"><span class="diff-meta">Schemas are identical.</span></div>';
        resultEl.innerHTML = head + `<div class="diff-list">${body}</div>` + (same ? `<p class="cmp-note">${same} table${same === 1 ? '' : 's'} identical (hidden).</p>` : '');

        const tgtEnv = envById[lastCmp.target];

        // Full "as-is" copy — only into a local target. Shown regardless of schema diff
        // (you may just want fresh production data even when the structure already matches).
        if (tgtEnv && tgtEnv.role === 'local') {
            resultEl.insertAdjacentHTML('beforeend', fullCopyBlock(s, t));
            const fc = $('fullcopy-btn');
            if (fc) fc.addEventListener('click', runFullCopy);
        }

        if (n > 0) {
            const canSync = tgtEnv && (tgtEnv.role === 'local' || tgtEnv.role === 'staging');
            const planBtn = canSync ? `<button id="plan-btn" class="btn-primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Build sync plan → ${esc(t.label)}</button>` : '';
            resultEl.insertAdjacentHTML('beforeend',
                `<div class="sync-actions">${planBtn}<button id="draft-btn" class="mini-btn"><i class="fa-solid fa-code-branch"></i> Draft migration from this diff</button></div><div id="plan-area"></div>`);
            if (canSync) $('plan-btn').addEventListener('click', buildPlan);
            $('draft-btn').addEventListener('click', draftMigration);
        }
    }

    /* ---------- full "as-is" copy (source → local) ---------- */
    function fullCopyBlock(s, t) {
        return '<div class="fullcopy">'
            + `<h3 class="drawer-h"><i class="fa-solid fa-download"></i> Fetch full copy → ${esc(t.label)} (as-is)</h3>`
            + `<p class="cmp-note">Replaces <b>${esc(t.db)}</b> with an exact copy of <b>${esc(s.label)}</b> · <b>${esc(s.db)}</b> — every table and row, dropping anything local-only. The local database is backed up first and the source is only read. Large databases can take a while.</p>`
            + `<div class="apply-row"><input id="fullcopy-confirm" placeholder="type &ldquo;${esc(t.db)}&rdquo; to confirm" autocomplete="off"><button id="fullcopy-btn" class="btn-danger"><i class="fa-solid fa-download"></i> Overwrite local with ${esc(s.db)}</button></div>`
            + '<div id="fullcopy-report"></div>';
    }
    async function runFullCopy() {
        const btn = $('fullcopy-btn'), rep = $('fullcopy-report');
        btn.disabled = true; btn.classList.add('busy');
        rep.innerHTML = '<p class="cmp-note">Dumping the source, backing up local, importing… this can take a while for large databases — leave the tab open.</p>';
        try {
            const r = await post({ action: 'full_copy', ...lastCmp, confirm: $('fullcopy-confirm').value });
            if (!r.ok) { rep.innerHTML = `<div class="diff-error"><i class="fa-solid fa-triangle-exclamation"></i> ${esc(r.error || 'Copy failed.')}</div>`; return; }
            const mb = (r.dump_bytes / 1048576).toFixed(1);
            const secs = (r.elapsed_ms / 1000).toFixed(1);
            rep.innerHTML = `<div class="sync-ok"><i class="fa-solid fa-check"></i> Copied <b>${esc(r.source.db)}</b> → <b>${esc(r.target.db)}</b>: ${r.tables} tables, ≈${Number(r.rows).toLocaleString()} rows (${mb} MB, ${secs}s).`
                + (r.backup ? `<br><small>Local backup: ${esc(r.backup)} &middot; dump: ${esc(r.dump_file)}</small>` : `<br><small>No prior local database to back up &middot; dump: ${esc(r.dump_file)}</small>`)
                + (r.pruned ? `<br><small class="muted">Auto-pruned ${r.pruned} old dump${r.pruned === 1 ? '' : 's'} — backups kept to the 5 most recent per database.</small>` : '')
                + (r.compat ? '<br><small class="muted">The source uses newer-server collations (uca1400 / 0900) — mapped to unicode_ci so this local MariaDB could import them. Data is unchanged; only sort/label of some text columns.</small>' : '')
                + (r.warn ? `<br><small class="muted">Notes: ${esc(r.warn)}</small>` : '')
                + '</div>';
        } catch (e) { rep.innerHTML = `<div class="diff-error">${esc(e.message || e)}</div>`; }
        finally { btn.disabled = false; btn.classList.remove('busy'); }
    }
    async function runCompare() {
        lastCmp = {
            group: $('cmp-group').value, source: $('cmp-source').value, target: $('cmp-target').value,
            source_user: $('cmp-source-user').value, source_pass: $('cmp-source-pass').value,
            target_user: $('cmp-target-user').value, target_pass: $('cmp-target-pass').value,
        };
        const btn = $('cmp-run'); btn.disabled = true; btn.classList.add('busy');
        summaryEl.innerHTML = ''; resultPanel.hidden = false;
        resultEl.innerHTML = '<div class="diff-row"><span class="diff-meta">Comparing…</span></div>';
        try { renderResult(await post({ action: 'compare', ...lastCmp })); }
        catch (e) { resultEl.innerHTML = `<div class="diff-error">${esc(e.message || e)}</div>`; }
        finally { btn.disabled = false; btn.classList.remove('busy'); }
    }

    /* ---------- Phase 2: plan + apply ---------- */
    async function buildPlan() {
        const btn = $('plan-btn'), area = $('plan-area');
        btn.disabled = true; btn.classList.add('busy');
        area.innerHTML = '<p class="cmp-note">Building plan…</p>';
        try {
            const d = await post({ action: 'plan', ...lastCmp });
            if (!d.ok) { area.innerHTML = `<div class="diff-error"><i class="fa-solid fa-triangle-exclamation"></i> ${esc(d.error)}</div>`; return; }
            renderPlan(d);
        } catch (e) { area.innerHTML = `<div class="diff-error">${esc(e.message || e)}</div>`; }
        finally { btn.disabled = false; btn.classList.remove('busy'); }
    }
    function renderPlan(d) {
        const p = d.plan, db = d.target.db;
        const sql = p.schema_sql.length ? esc(p.schema_sql.join(';\n') + ';') : 'No schema changes — structure already matches.';
        const skip = p.skipped.length ? `<div class="sync-skip"><b>${p.skipped.length} skipped (never auto-dropped)</b>${p.skipped.map((x) => `<div>• ${esc(x)}</div>`).join('')}</div>` : '';
        const checks = p.data_candidates.length ? p.data_candidates.map((tb) => `<label class="data-check"><input type="checkbox" value="${esc(tb)}"> ${esc(tb)}</label>`).join('') : '<span class="cmp-note">No tables available.</span>';
        $('plan-area').innerHTML =
            `<h3 class="drawer-h">Schema statements (${p.schema_sql.length}) → ${esc(d.target.label)}</h3>`
            + `<pre class="log-view">${sql}</pre>${skip}`
            + `<h3 class="drawer-h">Reference data (optional)</h3>`
            + `<p class="cmp-note">Checked tables are TRUNCATEd on the target and refilled from the source — pick only small lookup tables.</p>`
            + `<div class="data-checks">${checks}</div>`
            + `<div class="apply-row"><input id="confirm-db" placeholder="type “${esc(db)}” to apply" autocomplete="off"><button id="apply-btn" class="btn-danger"><i class="fa-solid fa-bolt"></i> Apply changes</button></div>`
            + `<div id="apply-report"></div>`;
        $('apply-btn').addEventListener('click', applyPlan);
    }
    async function applyPlan() {
        const btn = $('apply-btn'), rep = $('apply-report');
        btn.disabled = true; btn.classList.add('busy');
        rep.innerHTML = '<p class="cmp-note">Backing up local &amp; applying…</p>';
        const tables = [...document.querySelectorAll('.data-checks input:checked')].map((i) => i.value);
        try {
            const r = await post({ action: 'apply', ...lastCmp, data_tables: JSON.stringify(tables), confirm: $('confirm-db').value });
            if (!r.ok) { rep.innerHTML = `<div class="diff-error"><b>Stopped after ${r.executed}/${r.total}.</b> ${esc(r.error || '')}${r.backup ? '<br><small>Local backup saved: ' + esc(r.backup) + '</small>' : ''}</div>`; return; }
            const data = (r.data || []).map((x) => `${esc(x.table)} (${x.rows}${x.capped ? '+' : ''} rows)`).join(', ');
            rep.innerHTML = `<div class="sync-ok"><i class="fa-solid fa-check"></i> Applied ${r.executed}/${r.total} statements.${data ? ' Data: ' + data + '.' : ''}<br><small>Local backup: ${esc(r.backup)}</small></div>`;
        } catch (e) { rep.innerHTML = `<div class="diff-error">${esc(e.message || e)}</div>`; }
        finally { btn.disabled = false; btn.classList.remove('busy'); }
    }
    async function draftMigration() {
        const btn = $('draft-btn'); btn.disabled = true; btn.classList.add('busy');
        try {
            const d = await post({ action: 'mig_draft', ...lastCmp });
            if (!d.ok) { alert(d.error || 'Draft failed.'); return; }
            const sql = (d.sql && d.sql.trim()) ? d.sql : '-- No additive changes in that direction (target already has the source schema).';
            if (window.migFill) window.migFill(lastCmp.group, sql);
        } catch (e) { alert(e.message || e); }
        finally { btn.disabled = false; btn.classList.remove('busy'); }
    }

    /* ---------- wire up ---------- */
    envMgr.addEventListener('click', (e) => {
        const addEnv = e.target.closest('.grp-add-env');
        if (addEnv) { addEnv.closest('.env-group').querySelector('.env-rows').insertAdjacentHTML('beforeend', envRow({ role: 'staging', host: '100.110.57.68', port: 3306, db: '' })); return; }
        const delGrp = e.target.closest('.grp-del');
        if (delGrp) { delGrp.closest('.env-group').remove(); return; }
        const delEnv = e.target.closest('.env-del');
        if (delEnv) { delEnv.closest('.env-row').remove(); }
    });
    $('grp-add').addEventListener('click', () => {
        if (!envMgr.querySelector('.env-group')) envMgr.innerHTML = '';
        envMgr.insertAdjacentHTML('beforeend', groupBox({ name: 'New project', environments: [{ role: 'local', host: '127.0.0.1', port: 3306, db: '' }] }));
    });
    $('env-save').addEventListener('click', async () => {
        const status = $('env-status');
        status.textContent = 'Saving…';
        const d = await post({ action: 'save_envs', groups: JSON.stringify(collectGroups()) });
        if (d.ok) {
            environments = d.environments || [];
            renderGroups(d.groups);
            renderCompareForm();
            status.textContent = 'Saved ✓';
            setTimeout(() => { status.textContent = ''; }, 2000);
        } else { status.textContent = d.error || 'Save failed'; }
    });

    (async function init() {
        const d = await post({ action: 'envs' });
        environments = d.environments || [];
        renderGroups(d.groups || []);
        renderCompareForm();
    })();
})();
