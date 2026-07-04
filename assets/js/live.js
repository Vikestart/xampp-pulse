'use strict';
(function () {
    const API = '/xampp-pulse/live-api.php';
    const $ = (id) => document.getElementById(id);
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);
    const post = (p) => window.pulsePost(API, p);

    const listEl = $('live-list');
    if (!listEl) return;
    const countEl = $('live-count');
    let loaded = false;
    let monitors = [];

    function modal(title, bodyHtml) {
        const ov = document.createElement('div');
        ov.className = 'modal-overlay';
        ov.innerHTML = `<div class="modal"><div class="modal-head"><h2>${esc(title)}</h2><button class="icon-btn modal-x" type="button"><i class="fa-solid fa-xmark"></i></button></div><div class="modal-body">${bodyHtml}</div></div>`;
        document.body.appendChild(ov);
        document.body.classList.add('modal-open');
        const close = () => { ov.remove(); document.body.classList.remove('modal-open'); document.removeEventListener('keydown', onKey); };
        const onKey = (e) => { if (e.key === 'Escape') close(); };
        ov.addEventListener('click', (e) => { if (e.target === ov) close(); });
        ov.querySelector('.modal-x').addEventListener('click', close);
        document.addEventListener('keydown', onKey);
        return { el: ov.querySelector('.modal-body'), close };
    }

    function render() {
        countEl.textContent = String(monitors.length);
        if (!monitors.length) {
            listEl.innerHTML = '<p class="empty">No endpoints yet — click “Add endpoint” to monitor a production URL.</p>';
            return;
        }
        listEl.innerHTML = monitors.map((m, i) =>
            `<article class="live-card" data-i="${i}"><div class="live-top"><span class="live-dot checking"></span><span class="live-label">${esc(m.label)}</span>`
            + '<span class="live-btns"><button class="live-edit" type="button" title="Edit"><i class="fa-solid fa-pen"></i></button><button class="live-del" type="button" title="Remove"><i class="fa-solid fa-trash"></i></button></span></div>'
            + `<a class="live-url" href="${esc(m.url)}" target="_blank" rel="noopener">${esc(m.url)}</a>`
            + `<div class="live-stats" id="live-stat-${i}"><span class="empty">checking…</span></div></article>`).join('');
        monitors.forEach((m, i) => checkOne(m, i));
    }

    async function checkOne(m, i) {
        const stat = $('live-stat-' + i);
        const card = listEl.querySelector(`.live-card[data-i="${i}"]`);
        const dot = card && card.querySelector('.live-dot');
        try {
            const r = await post({ action: 'monitors_check', url: m.url });
            if (!r || !r.ok) { if (stat) stat.innerHTML = '<span class="live-down">error</span>'; return; }
            const state = r.code === 0 ? 'down' : (r.up ? 'up' : 'warn');
            if (dot) dot.className = 'live-dot ' + state;
            const badge = r.code === 0 ? '<span class="live-down">down</span>'
                : (r.up ? `<span class="live-ok">${r.code}</span>` : `<span class="live-warn">${r.code}</span>`);
            let cert = '';
            if (r.cert_days != null) {
                const cls = r.cert_days < 14 ? 'live-down' : (r.cert_days < 30 ? 'live-warn' : 'muted');
                cert = ` · <span class="${cls}">cert ${r.cert_days}d</span>`;
            }
            if (stat) stat.innerHTML = `${badge}${r.code !== 0 ? ' · ' + r.ms + ' ms' : ''}${cert}`;
        } catch (e) {
            if (stat) stat.innerHTML = '<span class="live-down">error</span>';
        }
    }

    async function load() {
        listEl.innerHTML = '<p class="empty">Loading…</p>';
        const r = await post({ action: 'monitors_list' });
        if (!r || !r.ok) { listEl.innerHTML = `<p class="empty">${esc(r && r.error ? r.error : 'Could not load.')}</p>`; return; }
        monitors = r.monitors || [];
        render();
        loaded = true;
    }

    async function save() {
        const r = await post({ action: 'monitors_save', monitors: JSON.stringify(monitors) });
        if (r && r.ok) { monitors = r.monitors || []; render(); }
    }

    function editModal(idx) {
        const cur = idx != null ? monitors[idx] : { label: '', url: '' };
        const m = modal(idx != null ? 'Edit endpoint' : 'Add endpoint',
            `<label class="m-label">Label</label><input class="m-input" id="lv-label" value="${esc(cur.label)}" placeholder="Production" autocomplete="off">`
            + `<label class="m-label">URL</label><input class="m-input" id="lv-url" value="${esc(cur.url)}" placeholder="https://example.com" autocomplete="off">`
            + '<div class="m-actions"><button class="btn-primary" id="lv-save" type="button"><i class="fa-solid fa-floppy-disk"></i> Save</button></div>');
        m.el.querySelector('#lv-save').addEventListener('click', () => {
            const url = m.el.querySelector('#lv-url').value.trim();
            if (!url) { m.el.querySelector('#lv-url').focus(); return; }
            const entry = { label: m.el.querySelector('#lv-label').value.trim(), url };
            if (idx != null) monitors[idx] = entry; else monitors.push(entry);
            m.close();
            save();
        });
        m.el.querySelector('#lv-label').focus();
    }

    listEl.addEventListener('click', (e) => {
        const card = e.target.closest('.live-card');
        if (!card) return;
        const i = +card.dataset.i;
        if (e.target.closest('.live-del')) { monitors.splice(i, 1); save(); return; }
        if (e.target.closest('.live-edit')) { editModal(i); }
    });
    $('live-add').addEventListener('click', () => editModal(null));
    $('live-refresh').addEventListener('click', render);

    function ensureLoaded() { if (!loaded) load(); }
    new MutationObserver(() => { if (document.documentElement.dataset.tab === 'live') ensureLoaded(); })
        .observe(document.documentElement, { attributes: true, attributeFilter: ['data-tab'] });
    if (document.documentElement.dataset.tab === 'live') ensureLoaded();
})();
