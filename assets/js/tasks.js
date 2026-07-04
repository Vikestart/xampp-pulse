'use strict';
(function () {
    const API = '/xampp-pulse/dev-api.php';
    const escMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => escMap[c]);
    const post = (p) => window.pulsePost(API, p);

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

    async function openTasks(folder) {
        const r = await post({ action: 'task_list', folder });
        if (!r || !r.ok) { window.alert(r && r.error ? r.error : 'Could not load tasks.'); return; }
        const tasks = r.tasks || [];
        let body = '';
        if (!tasks.length) {
            body = '<p class="cmp-note">No runnable tasks detected. Add a <code>package.json</code> (npm), an <code>artisan</code> file (Laravel), or install Composer.</p>';
        } else {
            const groups = {};
            tasks.forEach((t) => { (groups[t.group] = groups[t.group] || []).push(t); });
            Object.keys(groups).forEach((g) => {
                body += `<h3 class="drawer-h">${esc(g)}</h3><div class="task-btns">`
                    + groups[g].map((t) => `<button class="mini-btn task-run" type="button" data-task="${esc(t.key)}">${esc(t.label)}</button>`).join('')
                    + '</div>';
            });
        }
        body += '<div id="task-out-wrap" hidden><h3 class="drawer-h">Output <span id="task-state"></span></h3><pre class="task-out" id="task-out"></pre></div>';
        const m = modal('Tasks — ' + folder, body);

        let timer = null;
        function runTask(btn) {
            if (timer) return;
            const btns = m.el.querySelectorAll('.task-run');
            btns.forEach((x) => { x.disabled = true; });
            const wrap = m.el.querySelector('#task-out-wrap');
            const out = m.el.querySelector('#task-out');
            const state = m.el.querySelector('#task-state');
            wrap.hidden = false;
            out.textContent = '';
            state.textContent = 'starting…';
            post({ action: 'task_start', folder, task: btn.dataset.task }).then((sr) => {
                if (!sr || !sr.ok) {
                    state.innerHTML = `<span class="mail-err">${esc((sr && sr.error) || 'Failed to start.')}</span>`;
                    btns.forEach((x) => { x.disabled = false; });
                    return;
                }
                state.textContent = 'running…';
                const id = sr.id;
                timer = setInterval(async () => {
                    if (!out.isConnected) { clearInterval(timer); timer = null; return; } // modal closed
                    const pr = await post({ action: 'task_poll', id });
                    if (!pr || !pr.ok) return;
                    out.textContent = pr.output || '';
                    out.scrollTop = out.scrollHeight;
                    if (!pr.running) {
                        clearInterval(timer);
                        timer = null;
                        state.innerHTML = pr.code === 0 ? '<span class="task-ok"><i class="fa-solid fa-check"></i> done</span>' : `<span class="mail-err">exit ${pr.code}</span>`;
                        btns.forEach((x) => { x.disabled = false; });
                    }
                }, 1000);
            });
        }
        m.el.querySelectorAll('.task-run').forEach((b) => b.addEventListener('click', () => runTask(b)));
    }

    document.addEventListener('click', (e) => {
        const b = e.target.closest('.site-tasks');
        if (b) { e.preventDefault(); openTasks(b.dataset.folder); }
    });
})();
