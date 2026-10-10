</main>
<script>
window.CD = {
    csrf: <?= json_encode(csrfToken()) ?>,
    loggedIn: <?= json_encode((bool)(currentUser())) ?>,
    theme: <?= json_encode(currentTheme()) ?>,
    notifyTitle: <?= json_encode(t('notif_ticket_update_title')) ?>
};
</script>
<script src="js/app.js"></script>
</body>
</html>
