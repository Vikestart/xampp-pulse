'use strict';
(function () {
    const AUTH_API = '/xampp-pulse/auth-api.php';
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);
    const csrf = () => window.__PULSE_TOKEN__ || '';
    const getUnlock = () => { try { return sessionStorage.getItem('pulse-unlock') || ''; } catch (e) { return ''; } };
    const setUnlock = (t) => { try { sessionStorage.setItem('pulse-unlock', t); } catch (e) { /* ignore */ } };
    const clearUnlock = () => { try { sessionStorage.removeItem('pulse-unlock'); } catch (e) { /* ignore */ } };

    async function raw(url, body) {
        const r = await fetch(url, { method: 'POST', body: new URLSearchParams(body), cache: 'no-store' });
        return r.json();
    }

    /* Privileged POST: attaches csrf + unlock; on a lock response shows the gate, then retries once. */
    window.pulsePost = async function (url, body) {
        const b = Object.assign({}, body, { csrf: csrf(), unlock: getUnlock() });
        let j = await raw(url, b);
        if (j && j.locked) {
            const ok = await openGate(!!j.needs_setup);
            if (!ok) return j;
            b.unlock = getUnlock();
            j = await raw(url, b);
        }
        return j;
    };

    let pending = null;
    function openGate(needsSetup) {
        if (pending) return pending;
        pending = new Promise((resolve) => buildGate(needsSetup, resolve)).then((v) => { pending = null; return v; });
        return pending;
    }

    function buildGate(needsSetup, resolve) {
        const ov = document.createElement('div');
        ov.className = 'modal-overlay';
        const body = needsSetup
            ? '<p class="cmp-note">Set a passphrase to protect privileged actions (writing hosts/certs, restarting services, SSH, deploys). You’ll enter it once per session to unlock.</p>'
                + '<label class="m-label">New passphrase</label><input class="m-input" id="ag-p1" type="password" autocomplete="new-password">'
                + '<label class="m-label">Confirm</label><input class="m-input" id="ag-p2" type="password" autocomplete="new-password">'
            : '<p class="cmp-note">Enter your passphrase to unlock privileged actions for this session.</p>'
                + '<label class="m-label">Passphrase</label><input class="m-input" id="ag-p1" type="password" autocomplete="current-password">';
        ov.innerHTML = `<div class="modal"><div class="modal-head"><h2><i class="fa-solid fa-lock"></i> ${needsSetup ? 'Set passphrase' : 'Unlock'}</h2><button class="icon-btn modal-x" type="button"><i class="fa-solid fa-xmark"></i></button></div>`
            + `<div class="modal-body">${body}<div class="m-actions"><button class="btn-primary" id="ag-go" type="button">${needsSetup ? 'Set & unlock' : 'Unlock'}</button></div><div id="ag-status"></div></div></div>`;
        document.body.appendChild(ov);
        document.body.classList.add('modal-open');
        let done = false;
        const close = (val) => {
            if (done) return;
            done = true;
            ov.remove();
            document.body.classList.remove('modal-open');
            document.removeEventListener('keydown', onKey);
            resolve(val);
        };
        const onKey = (e) => { if (e.key === 'Escape') close(false); else if (e.key === 'Enter') go(); };
        ov.querySelector('.modal-x').addEventListener('click', () => close(false));
        ov.addEventListener('click', (e) => { if (e.target === ov) close(false); });
        document.addEventListener('keydown', onKey);
        const status = ov.querySelector('#ag-status');
        async function go() {
            const p1 = ov.querySelector('#ag-p1').value;
            if (needsSetup) {
                const p2 = ov.querySelector('#ag-p2').value;
                if (p1.length < 6) { status.innerHTML = '<div class="diff-error">At least 6 characters.</div>'; return; }
                if (p1 !== p2) { status.innerHTML = '<div class="diff-error">Passphrases don’t match.</div>'; return; }
            }
            status.textContent = 'Working…';
            const r = await raw(AUTH_API, { action: needsSetup ? 'set' : 'unlock', passphrase: p1, csrf: csrf() });
            if (r && r.ok && r.token) { setUnlock(r.token); setLockIcon(true); close(true); }
            else { status.innerHTML = `<div class="diff-error">${esc(r && r.error ? r.error : 'Failed.')}</div>`; }
        }
        ov.querySelector('#ag-go').addEventListener('click', go);
        const f = ov.querySelector('#ag-p1');
        if (f) f.focus();
    }

    const lockBtn = document.getElementById('lock-toggle');
    function setLockIcon(unlocked) {
        if (!lockBtn) return;
        lockBtn.innerHTML = `<i class="fa-solid fa-${unlocked ? 'lock-open' : 'lock'}"></i>`;
        lockBtn.classList.toggle('on', unlocked);
        lockBtn.title = unlocked ? 'Privileged actions unlocked — click to lock' : 'Locked — click to unlock';
    }
    async function refreshLock() {
        const s = await raw(AUTH_API, { action: 'status', csrf: csrf(), unlock: getUnlock() });
        setLockIcon(!!(s && s.unlocked));
    }
    if (lockBtn) lockBtn.addEventListener('click', async () => {
        const s = await raw(AUTH_API, { action: 'status', csrf: csrf(), unlock: getUnlock() });
        if (s && s.unlocked) {
            await raw(AUTH_API, { action: 'lock', csrf: csrf(), unlock: getUnlock() });
            clearUnlock();
            setLockIcon(false);
        } else {
            openGate(!s || !s.set);
        }
    });
    refreshLock();
})();
