'use strict';
(function () {
    const API = '/xampp-pulse/sites-api.php';
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

    function copy(text, btn) {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(text).then(() => {
            const o = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
            setTimeout(() => { btn.innerHTML = o; }, 1300);
        }).catch(() => {});
    }

    async function openGit(folder) {
        const r = await post({ action: 'git_status', folder });
        if (!r || !r.ok) { window.alert(r && r.error ? r.error : 'Could not read git status.'); return; }
        if (!r.is_repo) { modal('Git — ' + folder, '<p class="cmp-note">This site isn’t a git repository.</p>'); return; }

        const dirty = r.dirty > 0
            ? `<span class="tag warn">${r.dirty} change${r.dirty === 1 ? '' : 's'}</span>`
            : '<span class="tag ok">clean</span>';
        const ab = [];
        if (r.ahead) ab.push(`<span class="tag">↑ ${r.ahead} ahead</span>`);
        if (r.behind) ab.push(`<span class="tag warn">↓ ${r.behind} behind</span>`);
        const commits = (r.commits || []).map((c) =>
            `<div class="git-commit"><code>${esc(c.hash)}</code><span class="git-subj">${esc(c.subject)}</span><span class="git-meta">${esc(c.author)} · ${esc(c.when)}</span></div>`).join('');
        const win = String(r.root || '').replace(/\//g, '\\');
        const pullCmd = `git -C "${win}" pull`;
        const pushCmd = `git -C "${win}" push`;

        const m = modal('Git — ' + folder,
            `<div class="drawer-tags"><span class="tag"><i class="fa-solid fa-code-branch"></i> ${esc(r.branch)}</span>${dirty}${ab.join('')}</div>`
            + (r.remote ? `<div class="sys-row"><span>remote</span><b>${esc(r.remote)}</b></div>` : '')
            + (r.upstream ? `<div class="sys-row"><span>upstream</span><b>${esc(r.upstream)}</b></div>` : '')
            + '<h3 class="drawer-h">Recent commits</h3>'
            + `<div class="git-log">${commits || '<p class="empty">No commits.</p>'}</div>`
            + '<h3 class="drawer-h">Run in your terminal</h3>'
            + '<p class="cmp-note">Pull/push use <b>your</b> git credentials, so copy these and run them in your own terminal.</p>'
            + `<div class="git-cmds"><button class="mini-btn git-copy" type="button" data-cmd="${esc(pullCmd)}"><i class="fa-solid fa-copy"></i> Copy git pull</button>`
            + `<button class="mini-btn git-copy" type="button" data-cmd="${esc(pushCmd)}"><i class="fa-solid fa-copy"></i> Copy git push</button></div>`);
        m.el.querySelector('.git-cmds').addEventListener('click', (e) => {
            const b = e.target.closest('.git-copy');
            if (b) copy(b.dataset.cmd, b);
        });
    }

    document.addEventListener('click', (e) => {
        const b = e.target.closest('.site-git');
        if (b) { e.preventDefault(); openGit(b.dataset.folder); }
    });
})();
