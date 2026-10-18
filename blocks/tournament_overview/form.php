<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * @var array<string, string> $modeChoices
 * @var array<int, string> $tournamentChoices
 * @var int[] $selectedIDs
 */
$form = $form ?? app('helper/form');
$mode = $mode ?? 'event';
?>
<div class="mb-3">
    <?= $form->label('mode', t('Which tournaments')) ?>
    <?= $form->select('mode', $modeChoices, $mode) ?>
    <div class="form-text"><?= t('Several tournaments are shown as tabs. The Tournament List block may pick one with ?tournament=.') ?></div>
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
    <?= $form->checkbox('showCompleted', 1, (bool) ($showCompleted ?? 1)) ?>
    <?= $form->label('showCompleted', t('Show finished tournaments'), ['class' => 'form-check-label']) ?>
</div>
<div class="form-check mb-2">
    <?= $form->checkbox('showParticipants', 1, (bool) ($showParticipants ?? 1)) ?>
    <?= $form->label('showParticipants', t('Participants'), ['class' => 'form-check-label']) ?>
</div>
<div class="form-check mb-2">
    <?= $form->checkbox('showMatches', 1, (bool) ($showMatches ?? 1)) ?>
    <?= $form->label('showMatches', t('Matches (with result reporting)'), ['class' => 'form-check-label']) ?>
</div>
<div class="form-check mb-2">
    <?= $form->checkbox('showStandings', 1, (bool) ($showStandings ?? 1)) ?>
    <?= $form->label('showStandings', t('Standings'), ['class' => 'form-check-label']) ?>
</div>
<div class="form-check mb-3">
    <?= $form->checkbox('showBracket', 1, (bool) ($showBracket ?? 1)) ?>
    <?= $form->label('showBracket', t('Bracket (embedded from Challonge)'), ['class' => 'form-check-label']) ?>
</div>
<div class="row">
    <div class="col-6 mb-3">
        <?= $form->label('bracketHeight', t('Bracket height (px)')) ?>
        <?= $form->number('bracketHeight', $bracketHeight ?? 500, ['min' => 200, 'max' => 2000]) ?>
    </div>
    <div class="col-6 mb-3">
        <?= $form->label('refreshSeconds', t('Refresh every (seconds, 0 = off)')) ?>
        <?= $form->number('refreshSeconds', $refreshSeconds ?? 60, ['min' => 0, 'max' => 3600]) ?>
        <div class="form-text"><?= t('The page checks for changes (no Challonge requests) and reloads if there are any.') ?></div>
    </div>
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
