<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var \Concrete\Core\Form\Service\Form $form
 * @var array<string, string> $modeChoices
 * @var array<int, string> $tournamentChoices
 * @var int[] $selectedIDs
 * @var string $mode
 * @var int $showCompleted
 * @var int $detailPageID
 * @var string $title
 */
$form = $form ?? app('helper/form');
$pageSelector = app('helper/form/page_selector');
?>
<div class="mb-3">
    <?= $form->label('title', t('Title')) ?>
    <?= $form->text('title', $title ?? '', ['placeholder' => t('optional')]) ?>
</div>
<div class="mb-3">
    <?= $form->label('mode', t('Which tournaments')) ?>
    <?= $form->select('mode', $modeChoices, $mode ?? 'event') ?>
</div>
<div class="mb-3 ct-specify" style="<?= ($mode ?? 'event') === 'specify' ? '' : 'display:none' ?>">
    <?= $form->label('tournamentIDs', t('Tournaments')) ?>
    <?php if ($tournamentChoices === []) : ?>
        <div class="text-muted small"><?= t('No tournament is linked yet (Dashboard › Tournaments).') ?></div>
    <?php else : ?>
        <?= $form->selectMultiple('tournamentIDs', $tournamentChoices, $selectedIDs ?? [], ['size' => min(8, count($tournamentChoices))]) ?>
    <?php endif; ?>
</div>
<div class="form-check mb-3">
    <?= $form->checkbox('showCompleted', 1, (bool) ($showCompleted ?? 1)) ?>
    <?= $form->label('showCompleted', t('Show finished tournaments'), ['class' => 'form-check-label']) ?>
</div>
<div class="mb-3">
    <?= $form->label('detailPageID', t('Tournament page')) ?>
    <?= $pageSelector->selectPage('detailPageID', $detailPageID ?? 0) ?>
    <div class="form-text"><?= t('A page with the Tournament Overview block. Without it the cards link to Challonge.') ?></div>
</div>
<script>
    (function () {
        var mode = document.querySelector('select[name="mode"]');
        if (!mode) { return; }
        mode.addEventListener('change', function () {
            document.querySelectorAll('.ct-specify').forEach(function (el) { el.style.display = mode.value === 'specify' ? '' : 'none'; });
        });
    })();
</script>
