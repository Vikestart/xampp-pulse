'use strict';
(function () {
    const API = '/xampp-pulse/dev-api.php';
    const $ = (id) => document.getElementById(id);
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);

    const listEl = $('mail-list');
    if (!listEl) return;
    const viewEl = $('mail-view');
    const countEl = $('mail-count');
    const noteEl = $('mail-note');
    const toggleBtn = $('mail-toggle');
    const toggleLabel = $('mail-toggle-label');

    let loaded = false;
    let mailOn = false;
    let selected = null;

    const post = (p) => window.pulsePost(API, p);

    function relTime(ts) {
        const d = Math.floor(Date.now() / 1000) - ts;
        if (d < 60) return 'just now';
        if (d < 3600) return Math.floor(d / 60) + 'm ago';
        if (d < 86400) return Math.floor(d / 3600) + 'h ago';
        return new Date(ts * 1000).toLocaleString();
    }

    function setToggle() {
        toggleBtn.classList.toggle('on', mailOn);
        toggleLabel.textContent = mailOn ? 'Catching: on' : 'Enable catching';
        noteEl.innerHTML = mailOn
            ? '<i class="fa-solid fa-circle-check"></i> Outgoing <code>mail()</code> is captured here instead of being sent.'
            : 'Enable to capture every <code>mail()</code> your local apps send — nothing leaves your machine. Toggling restarts Apache.';
    }

    function renderList(items) {
        countEl.textContent = String(items.length);
        if (!items.length) {
            listEl.innerHTML = '<p class="empty">No caught mail yet.</p>';
            return;
        }
        listEl.innerHTML = items.map((m) =>
            `<button class="mail-item${m.id === selected ? ' active' : ''}" type="button" data-id="${esc(m.id)}">`
            + `<span class="mail-subj">${esc(m.subject)}</span>`
            + `<span class="mail-meta"><span class="mail-to">${esc(m.to || m.from || '')}</span><span class="mail-date">${relTime(m.date)}</span></span>`
            + '</button>').join('');
    }

    async function load() {
        listEl.innerHTML = '<p class="empty">Loading…</p>';
        const r = await post({ action: 'mail_list' });
        if (!r || !r.ok) { listEl.innerHTML = `<p class="empty">${esc(r && r.error ? r.error : 'Could not load.')}</p>`; return; }
        mailOn = !!r.on;
        setToggle();
        renderList(r.mail || []);
        loaded = true;
    }

    async function openMail(id) {
        selected = id;
        listEl.querySelectorAll('.mail-item').forEach((b) => b.classList.toggle('active', b.dataset.id === id));
        viewEl.innerHTML = '<p class="empty">Loading…</p>';
        const r = await post({ action: 'mail_read', id });
        if (!r || !r.ok) { viewEl.innerHTML = `<p class="empty">${esc(r && r.error ? r.error : 'Could not read.')}</p>`; return; }
        const h = r.headers || {};
        const rows = ['from', 'to', 'cc', 'subject', 'date'].filter((k) => h[k]).map((k) =>
            `<div class="mail-h"><span>${k}</span><b>${esc(h[k])}</b></div>`).join('');
        viewEl.innerHTML = `<div class="mail-head">${rows}<button class="mini-btn mail-del" type="button" data-id="${esc(id)}"><i class="fa-solid fa-trash"></i> Delete</button></div>`
            + `<pre class="mail-body">${esc(r.body || '(empty body)')}</pre>`;
    }

    async function toggle() {
        toggleBtn.disabled = true;
        noteEl.textContent = 'Working… Apache is restarting.';
        const r = await post({ action: mailOn ? 'mail_disable' : 'mail_enable' });
        if (r && r.ok) {
            mailOn = !!r.on;
            setTimeout(() => { setToggle(); toggleBtn.disabled = false; }, 2200);
        } else {
            noteEl.innerHTML = `<span class="mail-err">${esc(r && r.error ? r.error : 'Failed.')}</span>`;
            toggleBtn.disabled = false;
        }
    }

    listEl.addEventListener('click', (e) => {
        const it = e.target.closest('.mail-item');
        if (it) openMail(it.dataset.id);
    });
    viewEl.addEventListener('click', async (e) => {
        const del = e.target.closest('.mail-del');
        if (!del) return;
        await post({ action: 'mail_delete', id: del.dataset.id });
        selected = null;
        viewEl.innerHTML = '<p class="empty">Select a message to read it.</p>';
        load();
    });
    toggleBtn.addEventListener('click', toggle);
    $('mail-refresh').addEventListener('click', load);
    $('mail-clear').addEventListener('click', async () => {
        if (!window.confirm('Delete all caught mail?')) return;
        await post({ action: 'mail_clear' });
        selected = null;
        viewEl.innerHTML = '<p class="empty">Select a message to read it.</p>';
        load();
    });

    function ensureLoaded() { if (!loaded) load(); }
    new MutationObserver(() => { if (document.documentElement.dataset.tab === 'mail') ensureLoaded(); })
        .observe(document.documentElement, { attributes: true, attributeFilter: ['data-tab'] });
    if (document.documentElement.dataset.tab === 'mail') ensureLoaded();
})();
