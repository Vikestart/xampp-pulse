'use strict';
(function () {
    const API = '/xampp-pulse/dev-api.php';
    const $ = (id) => document.getElementById(id);
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);

    const iniEditor = $('ini-editor');
    if (!iniEditor) return;
    const post = (p) => window.pulsePost(API, p);

    /* ---------- Xdebug ---------- */
    const xToggle = $('xdebug-toggle');
    const xLabel = $('xdebug-label');
    const xNote = $('xdebug-note');
    let xOn = false;
    let xInstalled = false;

    function renderXdebug() {
        if (!xInstalled) {
            xToggle.disabled = true;
            xToggle.classList.remove('on');
            xLabel.textContent = 'Xdebug not installed';
            xNote.innerHTML = 'Xdebug isn’t installed on this XAMPP. Drop the matching <code>php_xdebug.dll</code> into <code>php/ext</code> (see the <a href="https://xdebug.org/wizard" target="_blank" rel="noopener">Xdebug wizard</a>) and this toggle will enable step‑debugging.';
            return;
        }
        xToggle.disabled = false;
        xToggle.classList.toggle('on', xOn);
        xLabel.textContent = xOn ? 'Xdebug: on' : 'Enable Xdebug';
        xNote.innerHTML = xOn
            ? '<i class="fa-solid fa-circle-check"></i> Xdebug is active (<code>develop,debug</code>). Toggling restarts Apache.'
            : 'Xdebug is installed but off — enable it for step‑debugging and richer errors. Toggling restarts Apache.';
    }

    async function loadXdebug() {
        const r = await post({ action: 'xdebug_status' });
        if (!r || !r.ok) {
            xNote.textContent = r && r.locked ? 'Unlock (lock icon, top‑right) to manage PHP tools.' : ((r && r.error) || 'Could not check Xdebug.');
            return;
        }
        xInstalled = !!r.installed;
        xOn = !!r.on;
        renderXdebug();
    }

    xToggle.addEventListener('click', async () => {
        xToggle.disabled = true;
        xNote.textContent = 'Working… Apache is restarting.';
        const r = await post({ action: xOn ? 'xdebug_disable' : 'xdebug_enable' });
        if (r && r.ok) { xOn = !!r.on; setTimeout(renderXdebug, 2200); }
        else { xNote.innerHTML = `<span class="mail-err">${esc((r && r.error) || 'Failed.')}</span>`; renderXdebug(); }
    });

    /* ---------- php.ini editor ---------- */
    const iniStatus = $('ini-status');
    const iniWrap = $('ini-wrap');
    const iniToggle = $('ini-toggle');
    let iniLoaded = false;
    const flash = (msg, kind) => { iniStatus.textContent = msg; iniStatus.className = 'ssh-status ' + (kind || ''); };

    async function loadIni() {
        flash('Loading…', '');
        const r = await post({ action: 'ini_read' });
        if (r && r.ok) { iniEditor.value = r.content || ''; iniLoaded = true; flash('', ''); }
        else flash(r && r.locked ? 'Unlock to edit php.ini.' : ((r && r.error) || 'Could not load.'), 'err');
    }

    iniToggle.addEventListener('click', () => {
        const open = !iniWrap.classList.contains('open');
        iniWrap.classList.toggle('open', open);
        iniToggle.classList.toggle('open', open);
        iniToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && !iniLoaded) loadIni();
    });
    $('ini-reload').addEventListener('click', loadIni);
    $('ini-save').addEventListener('click', async () => {
        const btn = $('ini-save');
        btn.disabled = true;
        flash('Validating & saving…', '');
        const r = await post({ action: 'ini_save', content: iniEditor.value });
        flash(r && r.ok ? (r.message || 'Saved.') : ((r && r.error) || 'Save failed.'), r && r.ok ? 'ok' : 'err');
        btn.disabled = false;
    });

    /* Lazy-check Xdebug when the System tab is first shown. */
    let sysLoaded = false;
    function ensureSys() { if (!sysLoaded) { sysLoaded = true; loadXdebug(); } }
    new MutationObserver(() => { if (document.documentElement.dataset.tab === 'system') ensureSys(); })
        .observe(document.documentElement, { attributes: true, attributeFilter: ['data-tab'] });
    if (document.documentElement.dataset.tab === 'system') ensureSys();
})();
