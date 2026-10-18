<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var array<string, string> $modeChoices
 * @var array<int, string> $tournamentChoices
 * @var int[] $selectedIDs
 */
$form = $form ?? app('helper/form');
$mode = $mode ?? 'all';
?>
<div class="mb-3">
    <?= $form->label('title', t('Title')) ?>
    <?= $form->text('title', $title ?? '', ['placeholder' => t('optional')]) ?>
</div>
<div class="mb-3">
    <?= $form->label('mode', t('Which tournaments')) ?>
    <?= $form->select('mode', $modeChoices, $mode) ?>
</div>
<div class="mb-3 ct-specify" style="<?= $mode === 'specify' ? '' : 'display:none' ?>">
    <?= $form->label('tournamentIDs', t('Tournaments')) ?>
    <?php if ($tournamentChoices === []) : ?>
        <div class="text-muted small"><?= t('No tournament is linked yet (Dashboard › Tournaments).') ?></div>
    <?php else : ?>
        <?= $form->selectMultiple('tournamentIDs', $tournamentChoices, $selectedIDs ?? [], ['size' => min(8, count($tournamentChoices))]) ?>
    <?php endif; ?>
</div>
<div class="form-check mb-2">
    <?= $form->checkbox('showCompleted', 1, (bool) ($showCompleted ?? 0)) ?>
    <?= $form->label('showCompleted', t('Include finished tournaments'), ['class' => 'form-check-label']) ?>
</div>
<div class="form-check mb-3">
    <?= $form->checkbox('showFinishedMatches', 1, (bool) ($showFinishedMatches ?? 1)) ?>
    <?= $form->label('showFinishedMatches', t('Show finished matches'), ['class' => 'form-check-label']) ?>
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
