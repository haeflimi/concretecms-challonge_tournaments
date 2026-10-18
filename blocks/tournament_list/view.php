<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Built-in UI (Bootstrap 5). Variables: $tournaments (presenter data), $detailURL (page of the overview block or
 * null), $title, $user, $presenter.
 *
 * @var array[] $tournaments
 * @var string|null $detailURL
 * @var string $title
 */
$stateClass = ['pending' => 'secondary', 'underway' => 'primary', 'complete' => 'success'];
?>
<div class="ct-block ct-tournament-list">
    <?php if ($title !== '') : ?><h3 class="ct-title"><?= h($title) ?></h3><?php endif; ?>
    <?php if ($tournaments === []) : ?>
        <p class="text-muted ct-empty"><?= t('No tournaments yet.') ?></p>
    <?php else : ?>
        <div class="row g-3">
            <?php foreach ($tournaments as $t) :
                $href = $detailURL ? $detailURL . '?tournament=' . (int) $t['id'] : $t['url'];
                $live = count($t['sections']['live']);
                ?>
                <div class="col-md-6 col-xl-4">
                    <a class="card h-100 text-decoration-none ct-card" href="<?= h($href) ?>" <?= $detailURL ? '' : 'target="_blank" rel="noopener"' ?>>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <h5 class="card-title mb-1"><?= h($t['name']) ?></h5>
                                <span class="badge bg-<?= $stateClass[$t['phase']] ?>"><?= h($t['stateLabel']) ?></span>
                            </div>
                            <div class="text-muted small mb-2"><?= h((string) $t['game']) ?> · <?= h($t['typeLabel']) ?></div>
                            <div class="small">
                                <i class="fas fa-users"></i> <?= t2('%d participant', '%d participants', $t['participantsCount']) ?>
                                <?php if ($live > 0) : ?><span class="ms-2 text-primary"><i class="fas fa-circle ct-live-dot"></i> <?= t2('%d match live', '%d matches live', $live) ?></span><?php endif; ?>
                                <?php if ($t['phase'] === 'pending' && $t['startsAtLabel']) : ?><span class="ms-2"><i class="far fa-clock"></i> <?= h($t['startsAtLabel']) ?></span><?php endif; ?>
                                <?php if ($t['phase'] === 'complete' && $t['standings'] !== [] && ($t['standings'][0]['rank'] ?? null) === 1) : ?><span class="ms-2"><i class="fas fa-crown text-warning"></i> <?= h($t['standings'][0]['participant']['name']) ?></span><?php endif; ?>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
