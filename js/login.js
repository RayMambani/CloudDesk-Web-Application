// Login page: Google sign-in (account chooser every time) and passkey sign-in.
(function () {
    'use strict';
    var cfg = window.CD_LOGIN || {};
    var status = document.getElementById('authStatus');
    function say(msg) { if (status) status.textContent = msg || ''; }

    // ---- Passkey / fingerprint / screen lock ----
    var pk = document.getElementById('passkeyBtn');
    if (pk && window.cdPasskey && window.cdPasskey.supported()) {
        pk.hidden = false;
        pk.addEventListener('click', function () {
            say('');
            window.cdPasskey.login().then(function (res) {
                if (res && res.ok) { window.location = res.redirect || 'dashboard.php'; }
                else { say((res && res.error) || cfg.msgNoPasskey); }
            }).catch(function () { say(cfg.msgNoPasskey); });
        });
    }

    // ---- Google (prompt=select_account so the user always picks the account) ----
    var g = document.getElementById('googleBtn');
    if (g && cfg.googleClientId) {
        g.addEventListener('click', function () {
            say('');
            if (!(window.google && google.accounts && google.accounts.oauth2)) {
                say('Google sign-in is still loading — try again in a moment.');
                return;
            }
            var client = google.accounts.oauth2.initTokenClient({
                client_id: cfg.googleClientId,
                scope: 'openid email profile',
                prompt: 'select_account',
                callback: function (resp) {
                    if (!resp || !resp.access_token) { say(cfg.msgFailed); return; }
                    fetch('google_login.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (window.CD && window.CD.csrf) || '' },
                        body: JSON.stringify({
                            access_token: resp.access_token,
                            remember: !!(document.getElementById('remember') || {}).checked
                        })
                    }).then(function (r) { return r.json(); }).then(function (d) {
                        if (d.ok) window.location = d.redirect || 'dashboard.php';
                        else say(d.error || cfg.msgFailed);
                    }).catch(function () { say(cfg.msgFailed); });
                }
            });
            client.requestAccessToken({ prompt: 'select_account' });
        });
    }
})();
