'use strict';
(function () {
    const API = '/xampp-pulse/ssh-api.php';
    const TOKEN = window.__PULSE_TOKEN__ || '';
    const $ = (id) => document.getElementById(id);
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);
    const OPT_NAMES = ['HostName', 'User', 'Port', 'IdentityFile', 'IdentitiesOnly', 'ProxyJump', 'ProxyCommand', 'ForwardAgent', 'AddKeysToAgent', 'PreferredAuthentications', 'PubkeyAuthentication', 'StrictHostKeyChecking', 'UserKnownHostsFile', 'ServerAliveInterval', 'ServerAliveCountMax', 'Compression', 'LocalForward', 'RemoteForward', 'ControlMaster', 'ControlPath', 'ControlPersist'];

    const editor = $('ssh-editor');
    if (!editor) return;
    const hostsEl = $('ssh-hosts');
    const pathEl = $('ssh-path');
    const profileEl = $('ssh-profile');
    const statusEl = $('ssh-status');
    const countEl = $('ssh-count');

    let loaded = false;
    let activeUser = '';
    let protoEnabled = false;

    async function post(p) {
        return window.pulsePost(API, p);
    }

    /* Split raw text into Host blocks with line spans (mirrors ssh_parse in lib/ssh.php). */
    function parseBlocks(text) {
        const lines = text.split(/\r?\n/);
        const blocks = [];
        let cur = null;
        for (let i = 0; i < lines.length; i++) {
            const t = lines[i].trim();
            if (t === '' || t[0] === '#') continue;
            const m = t.match(/^(\S+)[\s=]+(.*)$/);
            if (!m) continue;
            if (m[1].toLowerCase() === 'host') {
                if (cur) { cur.end = i; blocks.push(cur); }
                cur = { host: m[2].trim(), options: [], start: i, end: lines.length };
            } else if (cur) {
                cur.options.push([m[1], m[2].trim()]);
            }
        }
        if (cur) blocks.push(cur);
        return blocks;
    }

    function renderCards() {
        const blocks = parseBlocks(editor.value);
        if (countEl) countEl.textContent = String(blocks.length);
        if (!blocks.length) {
            hostsEl.innerHTML = '<p class="empty">No Host entries yet — use “Add host” or edit the raw config below.</p>';
            return;
        }
        hostsEl.innerHTML = blocks.map((b, idx) => {
            const opts = b.options.map(([k, v]) => `<div class="ssh-opt"><span>${esc(k)}</span><b>${esc(v)}</b></div>`).join('');
            const alias = b.host.split(/\s+/)[0];
            const connectable = alias && !/[*?!]/.test(alias);
            const connBtn = connectable ? `<button class="ssh-conn" type="button" data-ssh="${esc(alias)}" title="${protoEnabled ? 'Open terminal: ssh ' + esc(alias) : 'Copy: ssh ' + esc(alias)}"><i class="fa-solid fa-terminal"></i></button>` : '';
            return `<article class="ssh-card" data-idx="${idx}" draggable="true" title="Click to edit · drag to reorder">`
                + `<div class="ssh-card-head"><span class="ssh-host"><i class="fa-solid fa-server"></i> ${esc(b.host)}</span>`
                + connBtn
                + '<button class="ssh-edit" type="button" title="Edit this host"><i class="fa-solid fa-pen"></i></button>'
                + '<button class="ssh-dup" type="button" title="Duplicate this host"><i class="fa-solid fa-clone"></i></button>'
                + '<button class="ssh-remove" type="button" title="Remove this host"><i class="fa-solid fa-trash"></i></button></div>'
                + `<div class="ssh-opts">${opts || '<span class="empty">no options</span>'}</div></article>`;
        }).join('');
    }

    function removeBlock(idx) {
        const b = parseBlocks(editor.value)[idx];
        if (!b) return;
        const lines = editor.value.split(/\r?\n/);
        lines.splice(b.start, b.end - b.start);
        editor.value = lines.join('\n').replace(/\n{3,}/g, '\n\n').replace(/^\n+/, '');
        renderCards();
        save();
    }

    function reorderBlocks(fromIdx, toIdx) {
        if (fromIdx === toIdx) return;
        const lines = editor.value.split(/\r?\n/);
        const blocks = parseBlocks(editor.value);
        if (!blocks[fromIdx] || !blocks[toIdx]) return;
        const preamble = lines.slice(0, blocks[0].start);
        const texts = blocks.map((b) => lines.slice(b.start, b.end));
        const [moved] = texts.splice(fromIdx, 1);
        texts.splice(toIdx, 0, moved);
        editor.value = preamble.concat(...texts).join('\n').replace(/\n{3,}/g, '\n\n').replace(/^\n+/, '');
        renderCards();
        save();
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

    const optRow = (k, v, locked) =>
        `<div class="eh-row${locked ? ' locked' : ''}">`
        + `<input class="m-input eh-key" list="ssh-opt-names" placeholder="Option" value="${esc(k)}"${locked ? ' readonly' : ''} autocomplete="off">`
        + `<input class="m-input eh-val" placeholder="Value" value="${esc(v)}" autocomplete="off">`
        + (locked
            ? '<span class="eh-lock" title="Required — remove it in the raw config if you really need to"><i class="fa-solid fa-lock"></i></span>'
            : '<button class="eh-del" type="button" title="Remove option"><i class="fa-solid fa-xmark"></i></button>')
        + '</div>';

    /* One popup for both Add (idx === null) and Edit; applies + saves immediately. */
    function openHostModal(mode, idx) {
        const editing = mode === 'edit';
        const src = mode === 'add' ? { host: '', options: [] } : parseBlocks(editor.value)[idx];
        if (!src) return;
        const rest = src.options.slice();
        const pull = (name) => {
            const i = rest.findIndex(([k]) => k.toLowerCase() === name);
            return i >= 0 ? rest.splice(i, 1)[0][1] : '';
        };
        const hn = pull('hostname');
        const us = pull('user');
        let restRows = rest.map(([k, v]) => optRow(k, v, false)).join('');
        if (mode === 'add' && !restRows) restRows = optRow('Port', '22', false);
        const rows = optRow('HostName', hn, true) + optRow('User', us, true) + restRows;
        const title = editing ? 'Edit host' : (mode === 'duplicate' ? 'Duplicate host' : 'Add host');
        const m = modal(title,
            '<label class="m-label">Host alias</label>'
            + `<input class="m-input" id="eh-alias" value="${editing ? esc(src.host) : ''}" placeholder="my-server" autocomplete="off">`
            + '<label class="m-label">Options <span class="m-hint">(HostName &amp; User are required)</span></label>'
            + `<div id="eh-opts">${rows}</div>`
            + '<button id="eh-add-opt" class="mini-btn" type="button"><i class="fa-solid fa-plus"></i> Add option</button>'
            + `<datalist id="ssh-opt-names">${OPT_NAMES.map((n) => `<option value="${n}"></option>`).join('')}</datalist>`
            + `<div class="m-actions"><button class="btn-primary" id="eh-apply" type="button"><i class="fa-solid fa-${editing ? 'check' : 'plus'}"></i> ${editing ? 'Update host' : 'Add host'}</button></div>`);
        const optsEl = m.el.querySelector('#eh-opts');
        m.el.querySelector('#eh-add-opt').addEventListener('click', () => {
            optsEl.insertAdjacentHTML('beforeend', optRow('', '', false));
            const k = optsEl.lastElementChild.querySelector('.eh-key');
            if (k) k.focus();
        });
        optsEl.addEventListener('click', (e) => {
            const del = e.target.closest('.eh-del');
            if (del) del.closest('.eh-row').remove();
        });
        m.el.querySelector('#eh-apply').addEventListener('click', () => {
            const aliasEl = m.el.querySelector('#eh-alias');
            const alias = aliasEl.value.trim();
            if (!alias) { aliasEl.focus(); return; }
            const opts = [];
            optsEl.querySelectorAll('.eh-row').forEach((row) => {
                const key = row.querySelector('.eh-key').value.trim();
                const val = row.querySelector('.eh-val').value.trim();
                if (key && val) opts.push([key, val]);
            });
            applyHost(editing ? idx : null, alias, opts);
            m.close();
        });
        m.el.querySelector('#eh-alias').focus();
    }

    function applyHost(idx, alias, opts) {
        const rebuilt = ['Host ' + alias].concat(opts.map(([k, v]) => '    ' + k + ' ' + v));
        if (idx === null) {
            const base = editor.value.replace(/\s*$/, '');
            editor.value = (base ? base + '\n\n' : '') + rebuilt.join('\n') + '\n';
        } else {
            const lines = editor.value.split(/\r?\n/);
            const b = parseBlocks(editor.value)[idx];
            if (!b) return;
            const comments = lines.slice(b.start, b.end).filter((l) => l.trim()[0] === '#');
            const full = rebuilt.concat(comments);
            if (b.end < lines.length) full.push('');
            lines.splice(b.start, b.end - b.start, ...full);
            editor.value = lines.join('\n').replace(/\n{3,}/g, '\n\n');
        }
        renderCards();
        save();
    }

    function flash(msg, kind) {
        statusEl.textContent = msg;
        statusEl.className = 'ssh-status ' + (kind || '');
    }

    function connectHost(alias) {
        if (protoEnabled) {
            const a = document.createElement('a');
            a.href = 'pulsessh://' + encodeURIComponent(alias);
            a.click();
            flash('Opening a terminal for “' + alias + '”…', 'ok');
            return;
        }
        const cmd = 'ssh ' + alias;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(cmd).then(() => flash('Copied “' + cmd + '” — paste into your terminal.', 'ok')).catch(() => flash('Copy failed.', 'err'));
        } else {
            flash('Clipboard unavailable.', 'err');
        }
    }

    function fillProfiles(cands, active) {
        profileEl.innerHTML = (cands || []).map((c) =>
            `<option value="${esc(c.user)}"${c.user === active ? ' selected' : ''}>${esc(c.user)}${c.has_config ? '' : ' (no config)'}</option>`).join('');
        profileEl.style.display = (cands || []).length > 1 ? '' : 'none';
    }

    async function load(user) {
        pathEl.textContent = 'Loading…';
        try {
            const r = await post(user ? { action: 'load', user } : { action: 'load' });
            if (!r.ok) { pathEl.textContent = r.error || 'Could not load SSH config.'; return; }
            activeUser = r.active || '';
            protoEnabled = !!r.proto;
            setLaunchUI();
            fillProfiles(r.candidates, activeUser);
            pathEl.innerHTML = r.path
                ? `<i class="fa-solid fa-folder-open"></i> ${esc(r.path)}${r.exists ? '' : ' <span class="ssh-new">(will be created on save)</span>'}`
                : 'No user profiles found on this machine.';
            editor.value = r.content || '';
            renderCards();
            flash('', '');
            loaded = true;
        } catch (e) {
            pathEl.textContent = 'Request failed.';
        }
    }

    async function save() {
        if (!activeUser) { flash('No profile selected.', 'err'); return; }
        const btn = $('ssh-save');
        btn.disabled = true;
        flash('Saving…', '');
        try {
            const r = await post({ action: 'save', user: activeUser, content: editor.value });
            flash(r.ok ? (r.message || 'Saved.') : (r.error || 'Save failed.'), r.ok ? 'ok' : 'err');
            if (r.ok) renderCards();
        } catch (e) {
            flash('Request failed.', 'err');
        } finally {
            btn.disabled = false;
        }
    }

    let debounce;
    editor.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(renderCards, 250); });
    hostsEl.addEventListener('click', (e) => {
        const card = e.target.closest('.ssh-card');
        if (!card) return;
        const conn = e.target.closest('.ssh-conn');
        if (conn) { connectHost(conn.dataset.ssh); return; }
        const idx = +card.dataset.idx;
        if (e.target.closest('.ssh-dup')) { openHostModal('duplicate', idx); return; }
        if (e.target.closest('.ssh-remove')) { removeBlock(idx); return; }
        openHostModal('edit', idx);
    });
    let dragIdx = null;
    hostsEl.addEventListener('dragstart', (e) => {
        const card = e.target.closest('.ssh-card');
        if (!card) return;
        dragIdx = +card.dataset.idx;
        card.classList.add('dragging');
        if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(dragIdx)); }
    });
    hostsEl.addEventListener('dragover', (e) => {
        if (dragIdx === null) return;
        e.preventDefault();
        const card = e.target.closest('.ssh-card');
        hostsEl.querySelectorAll('.drop-target').forEach((el) => el.classList.remove('drop-target'));
        if (card && +card.dataset.idx !== dragIdx) card.classList.add('drop-target');
    });
    hostsEl.addEventListener('drop', (e) => {
        if (dragIdx === null) return;
        e.preventDefault();
        const card = e.target.closest('.ssh-card');
        if (card) reorderBlocks(dragIdx, +card.dataset.idx);
    });
    hostsEl.addEventListener('dragend', () => {
        hostsEl.querySelectorAll('.dragging, .drop-target').forEach((el) => el.classList.remove('dragging', 'drop-target'));
        dragIdx = null;
    });
    $('ssh-add').addEventListener('click', () => openHostModal('add'));
    $('ssh-save').addEventListener('click', save);
    profileEl.addEventListener('change', () => load(profileEl.value));

    const launchBtn = $('ssh-launch');
    const launchLabel = $('ssh-launch-label');
    function setLaunchUI() {
        if (!launchBtn) return;
        launchBtn.classList.toggle('on', protoEnabled);
        launchLabel.textContent = protoEnabled ? 'Terminal launch: on' : 'Enable terminal launch';
    }
    if (launchBtn) launchBtn.addEventListener('click', async () => {
        launchBtn.disabled = true;
        try {
            const r = await post({ action: protoEnabled ? 'proto_disable' : 'proto_enable' });
            if (r.ok) { protoEnabled = !!r.enabled; setLaunchUI(); renderCards(); flash(r.message || '', 'ok'); }
            else flash(r.error || 'Could not change the launcher.', 'err');
        } catch (e) {
            flash('Request failed.', 'err');
        } finally {
            launchBtn.disabled = false;
        }
    });

    const rawToggle = $('ssh-raw-toggle');
    const rawWrap = $('ssh-raw');
    const setRaw = (open) => {
        rawWrap.classList.toggle('open', open);
        rawToggle.classList.toggle('open', open);
        rawToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    rawToggle.addEventListener('click', () => {
        const open = !rawWrap.classList.contains('open');
        setRaw(open);
        try { localStorage.setItem('ssh-raw-open', open ? '1' : '0'); } catch (e) { /* ignore */ }
    });
    try { if (localStorage.getItem('ssh-raw-open') === '1') setRaw(true); } catch (e) { /* ignore */ }

    /* Lazy-load the first time the SSH tab is shown (via click, keyboard, or restore). */
    function ensureLoaded() { if (!loaded) load(); }
    new MutationObserver(() => {
        if (document.documentElement.dataset.tab === 'ssh') ensureLoaded();
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-tab'] });
    if (document.documentElement.dataset.tab === 'ssh') ensureLoaded();
})();
