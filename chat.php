<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();
$db = getDb();

$st = $db->prepare('SELECT * FROM chat_messages WHERE user_id = ? ORDER BY id ASC');
$st->execute([(int)$user['id']]);
$messages = $st->fetchAll();

if (!$messages) {
    $db->prepare('INSERT INTO chat_messages (user_id, sender_name, message, from_support, created_at) VALUES (?,?,?,1,?)')
       ->execute([(int)$user['id'], t('chat_support_name'), t('chat_greeting_format', $user['first_name']), nowStr()]);
    $st->execute([(int)$user['id']]);
    $messages = $st->fetchAll();
}
$lastId = $messages ? (int)end($messages)['id'] : 0;


$pageTitle = t('chat_title');
require __DIR__ . '/includes/header.php';
?>
<div class="row between">
    <h2><?= h(t('chat_title')) ?></h2>
    <span class="online"><?= h(t('chat_online_status')) ?></span>
</div>

<div class="card">
    <div class="chat-box">
        <div class="chat-messages" id="chatMessages" aria-live="polite">
            <?php foreach ($messages as $m): ?>
                <div class="bubble <?= $m['from_support'] ? 'received' : 'sent' ?>">
                    <?php if ($m['from_support']): ?><div class="who"><?= h(t('chat_support_name')) ?></div><?php endif; ?>
                    <?= nl2br(h($m['message'])) ?>
                    <div class="time"><?= h(date('H:i', strtotime($m['created_at']))) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="typing" class="muted small" hidden><?= h(t('chat_typing_indicator')) ?></div>
        <form class="chat-input" id="chatForm" autocomplete="off">
            <input type="text" id="chatInput" placeholder="<?= h(t('chat_input_hint')) ?>" maxlength="1000" aria-label="<?= h(t('chat_input_hint')) ?>">
            <button class="btn" type="submit">➤</button>
        </form>
    </div>
</div>

<script>
(function () {
    var lastId = <?= $lastId ?>;
    var box = document.getElementById('chatMessages');
    var typing = document.getElementById('typing');
    var support = <?= json_encode(t('chat_support_name')) ?>;

    function add(m) {
        if (m.id <= lastId && m.id !== 0) return;
        var d = document.createElement('div');
        d.className = 'bubble ' + (m.from_support ? 'received' : 'sent');
        if (m.from_support) { var w = document.createElement('div'); w.className = 'who'; w.textContent = support; d.appendChild(w); }
        var body = document.createElement('div'); body.style.whiteSpace = 'pre-wrap'; body.textContent = m.message; d.appendChild(body);
        var t = document.createElement('div'); t.className = 'time'; t.textContent = m.time; d.appendChild(t);
        box.appendChild(d);
        box.scrollTop = box.scrollHeight;
        if (m.id > lastId) lastId = m.id;
    }
    box.scrollTop = box.scrollHeight;

    document.getElementById('chatForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var input = document.getElementById('chatInput');
        var text = input.value.trim();
        if (!text) return;
        input.value = '';
        typing.hidden = false;
        fetch('chat_send.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CD.csrf },
            body: 'message=' + encodeURIComponent(text)
        }).then(function (r) { return r.json(); }).then(function (d) {
            typing.hidden = true;
            if (!d.ok) return;
            add(d.user_message); add(d.support_reply);
        }).catch(function () { typing.hidden = true; });
    });

    setInterval(function () {
        if (!navigator.onLine) return;
        fetch('chat_poll.php?since=' + lastId, { credentials: 'same-origin' }).then(function (r) { return r.json(); })
            .then(function (d) { (d.messages || []).forEach(add); }).catch(function () {});
    }, 5000);
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
