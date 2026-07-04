'use strict';
(function () {
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);
    const TABS = [['sites', 'Sites'], ['services', 'Services'], ['databases', 'Databases'], ['logs', 'Logs'], ['system', 'System'], ['ssh', 'SSH'], ['mail', 'Mail'], ['live', 'Live']];

    let overlay = null;
    let items = [];
    let filtered = [];
    let sel = 0;

    const goTab = (k) => { document.documentElement.dataset.tab = k; try { localStorage.setItem('dash-tab', k); } catch (e) { /* ignore */ } };
    const clickId = (id) => { const el = document.getElementById(id); if (el) el.click(); };

    function buildItems() {
        const list = [];
        TABS.forEach(([k, label]) => list.push({ label: 'Go to ' + label, sub: 'tab', icon: 'fa-arrow-right-long', run: () => goTab(k) }));
        (window.pulseSiteList ? window.pulseSiteList() : []).forEach((s) => list.push({
            label: s.name, sub: 'site', icon: 'fa-globe',
            run: () => { goTab('sites'); if (window.pulseOpenDrawer) window.pulseOpenDrawer(s.key); },
        }));
        list.push({ label: 'New site…', sub: 'action', icon: 'fa-plus', run: () => { goTab('sites'); clickId('site-new'); } });
        list.push({ label: 'Refresh now', sub: 'action', icon: 'fa-rotate', run: () => window.pulseRefresh && window.pulseRefresh() });
        list.push({ label: 'Toggle theme', sub: 'action', icon: 'fa-circle-half-stroke', run: () => clickId('theme-toggle') });
        list.push({ label: 'Lock / unlock', sub: 'action', icon: 'fa-lock', run: () => clickId('lock-toggle') });
        list.push({ label: 'Mail catcher', sub: 'action', icon: 'fa-envelope', run: () => goTab('mail') });
        return list;
    }

    function fuzzy(text, q) {
        let i = 0;
        for (let c = 0; c < text.length && i < q.length; c++) {
            if (text[c] === q[i]) i++;
        }
        return i === q.length;
    }

    function render() {
        const listEl = overlay.querySelector('.cmdk-list');
        if (!filtered.length) { listEl.innerHTML = '<div class="cmdk-empty">No matches.</div>'; return; }
        listEl.innerHTML = filtered.map((it, i) =>
            `<div class="cmdk-item${i === sel ? ' sel' : ''}" data-i="${i}"><i class="fa-solid ${it.icon}"></i><span>${esc(it.label)}</span><em>${esc(it.sub)}</em></div>`).join('');
        const s = listEl.querySelector('.cmdk-item.sel');
        if (s) s.scrollIntoView({ block: 'nearest' });
    }

    function filterList(q) {
        q = q.trim().toLowerCase();
        filtered = (q === '' ? items : items.filter((it) => fuzzy(it.label.toLowerCase(), q))).slice(0, 60);
        sel = 0;
        render();
    }

    function open() {
        if (overlay) return;
        items = buildItems();
        overlay = document.createElement('div');
        overlay.className = 'cmdk-overlay';
        overlay.innerHTML = '<div class="cmdk"><input class="cmdk-input" type="text" placeholder="Jump to a site, tab, or action…" autocomplete="off" spellcheck="false"><div class="cmdk-list"></div></div>';
        document.body.appendChild(overlay);
        document.body.classList.add('modal-open');
        const input = overlay.querySelector('.cmdk-input');
        input.addEventListener('input', () => filterList(input.value));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
        overlay.querySelector('.cmdk-list').addEventListener('click', (e) => {
            const el = e.target.closest('.cmdk-item');
            if (el) choose(+el.dataset.i);
        });
        filterList('');
        input.focus();
    }

    function close() {
        if (!overlay) return;
        overlay.remove();
        overlay = null;
        document.body.classList.remove('modal-open');
    }

    function choose(i) {
        const it = filtered[i];
        close();
        if (it) it.run();
    }

    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            overlay ? close() : open();
            return;
        }
        if (!overlay) return;
        if (e.key === 'Escape') { e.preventDefault(); close(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(sel + 1, filtered.length - 1); render(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(sel - 1, 0); render(); }
        else if (e.key === 'Enter') { e.preventDefault(); choose(sel); }
    });
})();
