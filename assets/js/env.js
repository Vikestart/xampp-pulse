'use strict';
(function () {
    const API = '/xampp-pulse/sites-api.php';
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);
    const post = (p) => window.pulsePost(API, p);
    const SECRET = /(PASS|PASSWORD|SECRET|KEY|TOKEN|PWD|CREDENTIAL|PRIVATE|SALT|CIPHER|DSN|AUTH)/i;

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

    const unquote = (v) => {
        v = v.trim();
        if (v.length >= 2 && ((v[0] === '"' && v.endsWith('"')) || (v[0] === "'" && v.endsWith("'")))) return v.slice(1, -1);
        return v;
    };
    const quote = (v) => (v !== '' && /[\s#"']/.test(v)) ? '"' + v.replace(/"/g, '\\"') + '"' : v;

    const row = (key, value, line) => {
        const secret = SECRET.test(key);
        return `<div class="eh-row" data-line="${line != null ? line : ''}">`
            + `<input class="m-input eh-key" value="${esc(key)}" ${line != null ? 'readonly' : 'placeholder="KEY"'} autocomplete="off">`
            + `<input class="m-input eh-val" type="${secret ? 'password' : 'text'}" value="${esc(value)}" placeholder="value" autocomplete="off">`
            + '<button class="env-eye" type="button" title="Show / hide"><i class="fa-solid fa-eye"></i></button>'
            + '<button class="eh-del" type="button" title="Remove"><i class="fa-solid fa-xmark"></i></button>'
            + '</div>';
    };

    async function openEnv(folder) {
        const r = await post({ action: 'env_read', folder });
        if (!r || !r.ok) { window.alert(r && r.error ? r.error : 'Could not read .env.'); return; }
        const lines = (r.content || '').split(/\r?\n/);
        const rows = [];
        lines.forEach((line, i) => {
            const m = line.match(/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/);
            if (m) rows.push(row(m[1], unquote(m[2]), i));
        });
        const note = r.exists ? esc(r.path)
            : (r.seeded ? 'New .env, seeded from .env.example → ' + esc(r.path) : 'No .env yet — this creates ' + esc(r.path));
        const m = modal('.env — ' + folder,
            `<p class="cmp-note">${note}</p>`
            + `<div id="env-rows">${rows.join('') || '<p class="eh-empty">No variables yet.</p>'}</div>`
            + '<button id="env-add" class="mini-btn" type="button"><i class="fa-solid fa-plus"></i> Add variable</button>'
            + '<div class="m-actions"><button class="btn-primary" id="env-save" type="button"><i class="fa-solid fa-floppy-disk"></i> Save .env</button></div><div id="env-status"></div>');
        const rowsEl = m.el.querySelector('#env-rows');
        m.el.querySelector('#env-add').addEventListener('click', () => {
            const empty = rowsEl.querySelector('.eh-empty');
            if (empty) empty.remove();
            rowsEl.insertAdjacentHTML('beforeend', row('', '', null));
            const k = rowsEl.lastElementChild.querySelector('.eh-key');
            if (k) k.focus();
        });
        rowsEl.addEventListener('click', (e) => {
            const del = e.target.closest('.eh-del');
            if (del) { del.closest('.eh-row').remove(); return; }
            const eye = e.target.closest('.env-eye');
            if (eye) { const inp = eye.parentElement.querySelector('.eh-val'); inp.type = inp.type === 'password' ? 'text' : 'password'; }
        });
        m.el.querySelector('#env-save').addEventListener('click', async () => {
            const byLine = {};
            const added = [];
            rowsEl.querySelectorAll('.eh-row').forEach((rw) => {
                const key = rw.querySelector('.eh-key').value.trim();
                if (!key) return;
                const val = rw.querySelector('.eh-val').value;
                const ln = rw.dataset.line;
                if (ln !== '') byLine[+ln] = { key, val };
                else added.push({ key, val });
            });
            const out = [];
            lines.forEach((line, i) => {
                if (/^([A-Za-z_][A-Za-z0-9_]*)\s*=/.test(line)) {
                    if (byLine[i]) out.push(byLine[i].key + '=' + quote(byLine[i].val)); // else: removed
                } else {
                    out.push(line);
                }
            });
            added.forEach((e) => out.push(e.key + '=' + quote(e.val)));
            const status = m.el.querySelector('#env-status');
            status.textContent = 'Saving…';
            const sr = await post({ action: 'env_save', folder, content: out.join('\n') });
            if (sr && sr.ok) {
                status.innerHTML = `<div class="sync-ok"><i class="fa-solid fa-check"></i> ${esc(sr.message || 'Saved.')}</div>`;
                setTimeout(m.close, 900);
            } else {
                status.innerHTML = `<div class="diff-error">${esc((sr && sr.error) || 'Save failed.')}</div>`;
            }
        });
    }

    document.addEventListener('click', (e) => {
        const b = e.target.closest('.site-env');
        if (b) { e.preventDefault(); openEnv(b.dataset.folder); }
    });
})();
