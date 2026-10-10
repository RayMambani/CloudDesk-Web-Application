<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = requireLogin();
$pageTitle = t('faq_title');
require __DIR__ . '/includes/header.php';
?>
<h2><?= h(t('faq_title')) ?></h2>
<p class="muted"><?= h(t('faq_subtitle')) ?></p>

<input type="search" id="faqSearch" placeholder="<?= h(t('faq_search_hint')) ?>" aria-label="<?= h(t('faq_search_hint')) ?>">

<div id="faqList">
<?php for ($i = 1; $i <= 6; $i++): ?>
    <details class="faq-item">
        <summary><?= h(t("faq_{$i}_title")) ?></summary>
        <div class="faq-body"><?= nl2br(h(t("faq_{$i}_body"))) ?></div>
    </details>
<?php endfor; ?>
</div>
<p class="muted" id="faqNone" hidden><?= h(t('faq_no_results')) ?></p>

<script>
document.getElementById('faqSearch').addEventListener('input', function (e) {
    var q = e.target.value.trim().toLowerCase(), shown = 0;
    document.querySelectorAll('#faqList .faq-item').forEach(function (d) {
        var match = !q || d.textContent.toLowerCase().indexOf(q) > -1;
        d.hidden = !match;
        if (match) shown++;
        if (q && match) d.open = true;
    });
    document.getElementById('faqNone').hidden = shown > 0;
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
