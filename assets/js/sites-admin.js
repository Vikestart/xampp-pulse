'use strict';
(function () {
    const API = '/xampp-pulse/sites-api.php';
    const TOKEN = window.__PULSE_TOKEN__ || '';
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);

    async function post(p) {
        p.csrf = TOKEN;
        const r = await fetch(API, { method: 'POST', body: new URLSearchParams(p), cache: 'no-store' });
        return r.json();
    }

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

    const field = (label, id, value, ph, type) =>
        `<label class="m-label">${esc(label)}</label><input id="${id}" class="m-input" value="${esc(value)}" placeholder="${esc(ph)}"${type ? ` type="${type}"` : ''} autocomplete="off">`;

    function renderLog(el, log) {
        const cls = { err: 'error', warn: 'warn', skip: 'notice' };
        const sym = { ok: '✓ ', warn: '⚠ ', skip: '‣ ', err: '✗ ', step: '— ' };
        el.innerHTML = (log || []).map((x) => `<div class="log-line ${cls[x.level] || ''}">${esc((sym[x.level] || '') + x.msg)}</div>`).join('');
    }

    async function runOp(m, params) {
        const btn = m.el.querySelector('#m-go');
        const status = m.el.querySelector('#m-status');
        btn.disabled = true; btn.classList.add('busy');
        status.innerHTML = '<p class="cmp-note">Working… Apache will restart in a moment.</p>';
        try {
            const r = await post(params);
            status.innerHTML = '<div class="log-view" id="m-log"></div>';
            if (r.log) renderLog(status.querySelector('#m-log'), r.log);
            if (r.ok) {
                status.insertAdjacentHTML('beforeend', `<div class="sync-ok"><i class="fa-solid fa-check"></i> ${esc(r.message || 'Done')} Refreshing…</div>`);
                setTimeout(() => {
                    if (window.pulseCloseDrawer) window.pulseCloseDrawer();
                    if (window.pulseRefresh) window.pulseRefresh();
                    m.close();
                }, 1900);
            } else {
                status.insertAdjacentHTML('beforeend', `<div class="diff-error"><i class="fa-solid fa-triangle-exclamation"></i> ${esc(r.error || 'Failed')}</div>`);
            }
        } catch (e) {
            status.innerHTML = `<div class="diff-error">${esc(e.message || e)}</div>`;
        } finally {
            btn.disabled = false; btn.classList.remove('busy');
        }
    }

    function openCreate() {
        const m = modal('Create a new site',
            field('Local domain', 'm-domain', '', 'example.localhost')
            + field('DocumentRoot folder (blank = from domain)', 'm-folder', '', 'example')
            + field('Log slug (blank = same as folder)', 'm-slug', '', 'example')
            + `<div class="m-actions"><button class="btn-primary" id="m-go" type="button"><i class="fa-solid fa-plus"></i> Create site</button></div><div id="m-status"></div>`);
        m.el.querySelector('#m-go').addEventListener('click', () => runOp(m, {
            action: 'create', domain: m.el.querySelector('#m-domain').value,
            folder: m.el.querySelector('#m-folder').value, slug: m.el.querySelector('#m-slug').value,
        }));
        m.el.querySelector('#m-domain').focus();
    }

    function openRename(domain, folder) {
        const m = modal('Rename ' + domain,
            field('New domain', 'm-domain', '', 'new-name.localhost')
            + field('DocumentRoot folder (created if missing)', 'm-folder', folder, '')
            + `<p class="cmp-note">The old folder htdocs/${esc(folder)} is never touched.</p>`
            + `<div class="m-actions"><button class="btn-primary" id="m-go" type="button"><i class="fa-solid fa-pen"></i> Rename</button></div><div id="m-status"></div>`);
        m.el.querySelector('#m-go').addEventListener('click', () => runOp(m, {
            action: 'rename', old: domain, new: m.el.querySelector('#m-domain').value, folder: m.el.querySelector('#m-folder').value,
        }));
        m.el.querySelector('#m-domain').focus();
    }

    function openRemove(domain, folder) {
        const m = modal('Remove ' + domain,
            `<p>This removes the hosts entry, both vhost blocks and the SSL certificate for <b>${esc(domain)}</b>.</p>`
            + `<p class="cmp-note">The project folder <b>htdocs/${esc(folder)}</b> will NOT be deleted.</p>`
            + `<label class="m-check"><input type="checkbox" id="m-keepcert"> Keep the certificate</label>`
            + `<div class="m-actions"><button class="btn-danger" id="m-go" type="button"><i class="fa-solid fa-trash"></i> Remove site</button></div><div id="m-status"></div>`);
        m.el.querySelector('#m-go').addEventListener('click', () => runOp(m, {
            action: 'remove', domain, keep_cert: m.el.querySelector('#m-keepcert').checked ? '1' : '',
        }));
    }

    const newBtn = document.getElementById('site-new');
    if (newBtn) newBtn.addEventListener('click', openCreate);

    const rootFix = document.getElementById('root-fix');
    if (rootFix) rootFix.addEventListener('click', async () => {
        const banner = document.getElementById('root-banner');
        const orig = rootFix.innerHTML;
        rootFix.disabled = true;
        rootFix.innerHTML = 'Fixing…';
        try {
            const r = await post({ action: 'fix_index' });
            const msg = banner && banner.querySelector('.rootbanner-msg span');
            if (r.ok && banner) {
                banner.classList.add('ok');
                const ic = banner.querySelector('.rootbanner-msg i');
                if (ic) ic.className = 'fa-solid fa-circle-check';
                if (msg) msg.innerHTML = '<b>Fixed.</b> localhost now serves XAMPP Pulse.';
                rootFix.remove();
                setTimeout(() => { banner.style.opacity = '0'; setTimeout(() => banner.remove(), 400); }, 2500);
            } else {
                rootFix.disabled = false;
                rootFix.innerHTML = orig;
                if (msg) msg.innerHTML = '<b>Could not fix it:</b> ' + esc(r.error || 'unknown error') + '.';
            }
        } catch (e) {
            rootFix.disabled = false;
            rootFix.innerHTML = orig;
        }
    });
    document.addEventListener('click', (e) => {
        const ren = e.target.closest('.site-rename');
        if (ren) { openRename(ren.dataset.domain, ren.dataset.folder); return; }
        const rem = e.target.closest('.site-remove');
        if (rem) { openRemove(rem.dataset.domain, rem.dataset.folder); }
    });
})();
