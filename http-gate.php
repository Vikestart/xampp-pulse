<?php
/**
 * Insecure-HTTP gate. render.php includes this (instead of the dashboard) when a request
 * arrives over plain http:// and the localhost certificate isn't trusted yet. It blocks all
 * dashboard usage over http: the only action offered is to issue & trust the localhost
 * certificate, after which the browser is upgraded to the https instance.
 *
 * Relies on $nonce (CSP nonce) and pulse_csrf_token() already being available from render.php.
 * The privileged fix runs through the normal auth gate (pulsePost → set/unlock modal), so the
 * cert can only be trusted by someone who can set/enter the passphrase.
 */
$httpsUrl = 'https://' . $__host . ($_SERVER['REQUEST_URI'] ?? '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>XAMPP Pulse — secure connection required</title>
<link rel="icon" href="/xampp-pulse/assets/img/icon.svg">
<link rel="stylesheet" href="/xampp-pulse/assets/font-awesome/css/all.min.css">
<link rel="stylesheet" href="/xampp-pulse/assets/css/dashboard.css">
<style>
    body{min-height:100vh;display:grid;place-items:center;padding:24px;}
    .gate{max-width:460px;width:100%;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:30px 28px;text-align:center;}
    .gate-ic{width:58px;height:58px;margin:0 auto 16px;display:grid;place-items:center;border-radius:16px;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;font-size:25px;box-shadow:0 6px 18px rgba(37,99,235,.35);}
    .gate h1{margin:0 0 10px;font-size:20px;color:var(--ink);}
    .gate p{margin:0 0 20px;color:var(--muted);font-size:14px;line-height:1.6;}
    .gate code{background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:1px 6px;font-size:12.5px;color:var(--ink);}
    .gate .btn-primary{width:100%;justify-content:center;}
    .gate-status{margin-top:16px;font-size:13px;line-height:1.5;color:var(--muted);min-height:18px;}
    .gate-status.err{color:var(--down);}
    .gate-status.ok{color:var(--up);}
    .gate-foot{margin-top:18px;font-size:12px;color:var(--muted);}
</style>
<script nonce="<?= $nonce ?>">(function(){try{var d=document.documentElement,t=localStorage.getItem('dash-theme');if(t)d.dataset.theme=t;}catch(e){}})();</script>
</head>
<body>
<main class="gate">
    <div class="gate-ic"><i class="fa-solid fa-shield-halved"></i></div>
    <h1>Secure connection required</h1>
    <p>XAMPP Pulse only runs over <b>HTTPS</b>. Your <code>localhost</code> certificate isn&rsquo;t trusted yet, so the dashboard is blocked over insecure <code>http://</code>. Trust the certificate to continue &mdash; this issues &amp; trusts a proper localhost cert and restarts Apache briefly.</p>
    <button class="btn-primary" id="gate-go" type="button"><i class="fa-solid fa-lock"></i> Trust certificate &amp; continue</button>
    <div class="gate-status" id="gate-status" role="status"></div>
    <div class="gate-foot">If the padlock still shows &ldquo;Not secure&rdquo; afterwards, fully quit and reopen your browser once.</div>
</main>
<script nonce="<?= $nonce ?>">window.__PULSE_TOKEN__ = <?= json_encode(pulse_csrf_token()) ?>;</script>
<script defer src="/xampp-pulse/assets/js/auth.js"></script>
<script nonce="<?= $nonce ?>">
(function () {
    'use strict';
    var HTTPS_URL = <?= json_encode($httpsUrl) ?>;
    var btn = document.getElementById('gate-go');
    var status = document.getElementById('gate-status');
    btn.addEventListener('click', async function () {
        if (!window.pulsePost) { status.className = 'gate-status'; status.textContent = 'Still loading — try again in a second.'; return; }
        var orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Trusting…';
        status.className = 'gate-status';
        status.textContent = '';
        try {
            var r = await window.pulsePost('/xampp-pulse/sites-api.php', { action: 'fix_localhost_cert' });
            if (r && r.ok) {
                status.className = 'gate-status ok';
                status.textContent = 'Certificate trusted. Switching to HTTPS…';
                btn.innerHTML = 'Switching…';
                setTimeout(function () { location.replace(HTTPS_URL); }, 4500);
            } else if (r && r.locked) {
                // The passphrase modal was dismissed without unlocking.
                btn.disabled = false;
                btn.innerHTML = orig;
                status.className = 'gate-status err';
                status.textContent = 'Set or enter your passphrase to trust the certificate.';
            } else {
                btn.disabled = false;
                btn.innerHTML = orig;
                status.className = 'gate-status err';
                status.textContent = (r && r.error) ? r.error : 'Could not trust the certificate.';
            }
        } catch (e) {
            btn.disabled = false;
            btn.innerHTML = orig;
            status.className = 'gate-status err';
            status.textContent = 'Request failed — is Apache running?';
        }
    });
})();
</script>
</body>
</html>
