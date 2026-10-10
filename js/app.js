// CloudDesk Web — shared behaviour: menus, theme, offline banner, notifications, logout confirm.
(function () {
    'use strict';
    var CD = window.CD || {};

    function $(id) { return document.getElementById(id); }

    // ---- Dropdown menus ----
    function wireMenu(btnId, menuId) {
        var btn = $(btnId), menu = $(menuId);
        if (!btn || !menu) return;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = menu.hidden;
            document.querySelectorAll('.dropdown').forEach(function (d) { d.hidden = true; });
            menu.hidden = !open;
        });
        menu.addEventListener('click', function (e) { e.stopPropagation(); });
    }
    wireMenu('avatarBtn', 'avatarMenu');
    wireMenu('bellBtn', 'bellMenu');
    document.addEventListener('click', function () {
        document.querySelectorAll('.dropdown').forEach(function (d) { d.hidden = true; });
    });

    // ---- Mobile nav ----
    var navToggle = $('navToggle'), nav = $('mainnav');
    if (navToggle && nav) {
        navToggle.addEventListener('click', function () {
            var open = nav.classList.toggle('open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // ---- Theme toggle (saved in a cookie and, when logged in, on the account) ----
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        document.cookie = 'cd_theme=' + theme + ';path=/;max-age=31536000;SameSite=Lax';
        CD.theme = theme;
    }
    var themeBtn = $('themeBtn');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var next = (CD.theme === 'light') ? 'dark' : 'light';
            applyTheme(next);
            if (CD.loggedIn) {
                fetch('prefs_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CD.csrf },
                    body: 'theme=' + encodeURIComponent(next)
                });
            }
            if (window.cdRedrawCharts) window.cdRedrawCharts();
        });
    }
    document.querySelectorAll('input[name="theme"][data-live-theme]').forEach(function (r) {
        r.addEventListener('change', function () { if (r.checked) applyTheme(r.value); });
    });

    // ---- Offline banner ----
    var offline = $('offlineBanner');
    function updateOnline() { if (offline) offline.hidden = navigator.onLine; }
    window.addEventListener('online', updateOnline);
    window.addEventListener('offline', updateOnline);
    updateOnline();

    // ---- Logout confirmation ----
    var logout = $('logoutLink');
    if (logout) {
        logout.addEventListener('click', function (e) {
            var msg = (logout.dataset.title || '') + '\n\n' + (logout.dataset.message || '');
            if (!window.confirm(msg)) e.preventDefault();
        });
    }

    // ---- Notification polling (bell badge + optional desktop alerts) ----
    var lastSeen = null;
    function pollNotifications() {
        if (!CD.loggedIn || !navigator.onLine) return;
        fetch('notifications_api.php?action=poll', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.ok) return;
                var badge = $('bellCount');
                if (badge) {
                    badge.hidden = d.unread === 0;
                    badge.textContent = d.unread > 99 ? '99+' : d.unread;
                }
                if (lastSeen !== null && d.latest_id > lastSeen && d.latest && 'Notification' in window && Notification.permission === 'granted') {
                    try { new Notification(d.latest.title, { body: d.latest.body }); } catch (e) { /* ignore */ }
                }
                lastSeen = d.latest_id;
            })
            .catch(function () { /* offline or server busy — try again next time */ });
    }
    if (CD.loggedIn) {
        pollNotifications();
        setInterval(pollNotifications, 30000);
    }
    window.cdEnableDesktopAlerts = function () {
        if (!('Notification' in window)) return;
        Notification.requestPermission();
    };
})();
