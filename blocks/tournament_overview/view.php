<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Built-in UI (Bootstrap 5). Variables: $tournaments (presenter data, the picked one first), $options, $user,
 * $token, $tokenName, $actionBase, $returnURL, $loginURL, $messages, $bID, $presenter.
 *
 * @var array[] $tournaments
 * @var array{showParticipants: bool, showMatches: bool, showStandings: bool, showBracket: bool, bracketHeight: int, refreshSeconds: int} $options
 * @var \Concrete\Core\User\User|null $user
 * @var \Concrete\Core\Validation\CSRF\Token $token
 * @var string $tokenName
 * @var string $actionBase
 * @var string $returnURL
 * @var string $loginURL
 * @var array{success: string[], error: string[]} $messages
 * @var int $bID
 */
$stateClass = ['pending' => 'secondary', 'underway' => 'primary', 'complete' => 'success'];
$renderMatch = static function (array $m, array $t) use ($token, $tokenName, $actionBase, $returnURL) {
    $p1 = $m['p1']['name'] ?? null;
    $p2 = $m['p2']['name'] ?? null;
    $isLive = $m['underway'];
    ?>
    <div class="ct-match list-group-item <?= $isLive ? 'ct-match-live' : '' ?> <?= $m['state'] === 'complete' ? 'ct-match-complete' : '' ?>" data-match="<?= (int) $m['id'] ?>">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="ct-match-id text-muted small"><?= h((string) $m['identifier']) ?> · <?= h($m['roundLabel']) ?></span>
            <?php if ($isLive) : ?><span class="badge bg-danger"><i class="fas fa-circle ct-live-dot"></i> <?= t('Live') ?></span><?php endif; ?>
            <?php if ($m['scheduledLabel'] && $m['state'] !== 'complete') : ?><span class="small text-muted"><i class="far fa-clock"></i> <?= h($m['scheduledLabel']) ?></span><?php endif; ?>
            <?php if ($m['pending']) : ?><span class="badge bg-warning text-dark" title="<?= h($m['pending']['note'] ?? '') ?>"><i class="fas fa-flag"></i> <?= t('result reported') ?></span><?php endif; ?>
        </div>
        <div class="ct-sides d-flex align-items-center gap-2 my-1">
            <span class="ct-side flex-grow-1 text-end <?= $m['winnerSide'] === 1 ? 'ct-winner' : '' ?> <?= ($m['p1']['id'] ?? null) !== null && in_array($m['p1']['id'], array_column(array_filter($t['participants'], static function ($p) { return $p['mine']; }), 'id'), true) ? 'ct-mine' : '' ?>"><?= $p1 !== null ? h($p1) : '<em class="text-muted">' . t('tbd') . '</em>' ?></span>
            <span class="ct-score badge bg-light text-dark">
                <?php if ($m['setWins'] !== null) : ?><?= (int) $m['setWins'][0] ?> : <?= (int) $m['setWins'][1] ?><?php else : ?><?= t('vs') ?><?php endif; ?>
            </span>
            <span class="ct-side flex-grow-1 <?= $m['winnerSide'] === 2 ? 'ct-winner' : '' ?> <?= ($m['p2']['id'] ?? null) !== null && in_array($m['p2']['id'], array_column(array_filter($t['participants'], static function ($p) { return $p['mine']; }), 'id'), true) ? 'ct-mine' : '' ?>"><?= $p2 !== null ? h($p2) : '<em class="text-muted">' . t('tbd') . '</em>' ?></span>
        </div>
        <?php if (count($m['sets']) > 1) : ?>
            <div class="text-center small text-muted ct-sets"><?= h(implode(' · ', array_map(static function ($s) { return $s[0] . '-' . $s[1]; }, $m['sets']))) ?></div>
        <?php endif; ?>
        <?php if ($m['pending']) : ?>
            <div class="ct-pending alert alert-warning py-2 px-3 mt-2 mb-0 small">
                <strong><?= t('%1$s wins %2$s', h($m['pending']['winnerName']), h($m['pending']['scores'])) ?></strong>
                — <?= $m['pending']['mine'] ? t('your report, waiting for the other team or a moderator') : ($m['pending']['canConfirm'] ? t('reported by the other team: do you agree?') : t('waiting for confirmation')) ?>
                <?php if ($m['pending']['canConfirm'] || $m['pending']['canWithdraw']) : ?>
                    <div class="mt-2 d-flex gap-2 flex-wrap">
                        <?php if ($m['pending']['canConfirm']) : ?>
                            <form method="post" action="<?= h($actionBase . '/confirm/' . (int) $m['pending']['id']) ?>" class="ct-ajax"><?php $token->output($tokenName) ?><input type="hidden" name="return" value="<?= h($returnURL) ?>"><input type="hidden" name="tournament" value="<?= (int) $t['id'] ?>"><button class="btn btn-sm btn-success"><i class="fas fa-check"></i> <?= t('Confirm') ?></button></form>
                            <form method="post" action="<?= h($actionBase . '/reject/' . (int) $m['pending']['id']) ?>" class="ct-ajax"><?php $token->output($tokenName) ?><input type="hidden" name="return" value="<?= h($returnURL) ?>"><input type="hidden" name="tournament" value="<?= (int) $t['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-times"></i> <?= t('Disagree') ?></button></form>
                        <?php endif; ?>
                        <?php if ($m['pending']['canWithdraw'] && $m['pending']['mine']) : ?>
                            <form method="post" action="<?= h($actionBase . '/withdraw/' . (int) $m['pending']['id']) ?>" class="ct-ajax"><?php $token->output($tokenName) ?><input type="hidden" name="return" value="<?= h($returnURL) ?>"><input type="hidden" name="tournament" value="<?= (int) $t['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><?= t('Withdraw') ?></button></form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($m['canReport'] || $m['canToggleUnderway'] || $m['canReopen']) : ?>
            <div class="ct-actions mt-2 d-flex gap-2 flex-wrap align-items-center">
                <?php if ($m['canToggleUnderway']) : ?>
                    <form method="post" action="<?= h($actionBase . '/report/' . (int) $m['id']) ?>" class="ct-ajax"><?php $token->output($tokenName) ?><input type="hidden" name="return" value="<?= h($returnURL) ?>"><input type="hidden" name="tournament" value="<?= (int) $t['id'] ?>"><input type="hidden" name="underway" value="<?= $isLive ? 0 : 1 ?>"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-<?= $isLive ? 'pause' : 'play' ?>"></i> <?= $isLive ? t('Not live anymore') : t('We are playing') ?></button></form>
                <?php endif; ?>
                <?php if ($m['canReport'] && !$m['pending']) : ?>
                    <button type="button" class="btn btn-sm btn-primary ct-toggle-report" data-target="#ct-report-<?= (int) $m['id'] ?>"><i class="fas fa-flag-checkered"></i> <?= t('Report result') ?></button>
                <?php elseif ($m['canReport'] && $m['pending'] && !$m['pending']['mine'] && !$m['pending']['canConfirm']) : ?>
                    <button type="button" class="btn btn-sm btn-outline-primary ct-toggle-report" data-target="#ct-report-<?= (int) $m['id'] ?>"><?= t('Report differently') ?></button>
                <?php endif; ?>
                <?php if ($m['canReopen']) : ?>
                    <form method="post" action="<?= h($actionBase . '/report/' . (int) $m['id']) ?>" class="ct-ajax" data-confirm="<?= h(t('Reopen this match on Challonge?')) ?>"><?php $token->output($tokenName) ?><input type="hidden" name="return" value="<?= h($returnURL) ?>"><input type="hidden" name="tournament" value="<?= (int) $t['id'] ?>"><input type="hidden" name="reopen" value="1"><button class="btn btn-sm btn-outline-danger"><?= t('Reopen') ?></button></form>
                <?php endif; ?>
            </div>
            <?php if ($m['canReport']) : ?>
                <form method="post" action="<?= h($actionBase . '/report/' . (int) $m['id']) ?>" class="ct-ajax ct-report-form mt-2" id="ct-report-<?= (int) $m['id'] ?>" style="display:none">
                    <?php $token->output($tokenName) ?>
                    <input type="hidden" name="return" value="<?= h($returnURL) ?>">
                    <input type="hidden" name="tournament" value="<?= (int) $t['id'] ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-5">
                            <label class="form-label small mb-1"><?= t('Winner') ?></label>
                            <select name="winner" class="form-select form-select-sm" required>
                                <option value=""><?= t('— choose —') ?></option>
                                <option value="1" <?= $m['mySide'] === 1 ? 'selected' : '' ?>><?= h($p1) ?></option>
                                <option value="2" <?= $m['mySide'] === 2 ? 'selected' : '' ?>><?= h($p2) ?></option>
                            </select>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small mb-1"><?= t('Scores (%s first)', h($p1)) ?></label>
                            <input type="text" name="scores" class="form-control form-control-sm" placeholder="16-9,10-16,16-12" required pattern="\s*-?\d+\s*-\s*-?\d+(\s*,\s*-?\d+\s*-\s*-?\d+)*\s*">
                        </div>
                        <div class="col-sm-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100"><?= t('Send') ?></button>
                        </div>
                    </div>
                    <div class="form-text"><?= t('One score per map, separated by commas. The other team or a moderator confirms before it counts.') ?></div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
};
?>
<div class="ct-block ct-tournament-overview" id="ct-overview-<?= (int) $bID ?>" data-action-base="<?= h($actionBase) ?>" data-refresh="<?= (int) $options['refreshSeconds'] ?>">
    <?php foreach (['success' => 'success', 'error' => 'danger'] as $type => $class) :
        foreach ($messages[$type] as $message) : ?>
            <div class="alert alert-<?= $class ?> alert-dismissible"><?= h($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endforeach;
    endforeach; ?>
    <div class="ct-messages"></div>

    <?php if ($tournaments === []) : ?>
        <p class="text-muted ct-empty"><?= t('No tournaments yet.') ?></p>
    <?php else : ?>
        <?php if (count($tournaments) > 1) : ?>
            <ul class="nav nav-tabs ct-tabs mb-3" role="tablist">
                <?php foreach ($tournaments as $i => $t) : ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $i === 0 ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#ct-t-<?= (int) $bID ?>-<?= (int) $t['id'] ?>" type="button" role="tab">
                            <?= h($t['name']) ?>
                            <?php if (count($t['sections']['live']) > 0) : ?><span class="badge bg-danger ms-1"><?= count($t['sections']['live']) ?></span><?php endif; ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="tab-content">
            <?php foreach ($tournaments as $i => $t) :
                $byID = [];
                foreach ($t['matches'] as $m) {
                    $byID[$m['id']] = $m;
                }
                ?>
                <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?> ct-tournament" id="ct-t-<?= (int) $bID ?>-<?= (int) $t['id'] ?>" role="tabpanel" data-tournament="<?= (int) $t['id'] ?>" data-changed="<?= h((string) $t['lastChangedAt']) ?>">
                    <header class="ct-head d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h3 class="mb-1"><?= h($t['name']) ?></h3>
                            <div class="text-muted small">
                                <?= h((string) $t['game']) ?> · <?= h($t['typeLabel']) ?> · <?= t2('%d participant', '%d participants', $t['participantsCount']) ?>
                                <?php if ($t['phase'] === 'pending' && $t['startsAtLabel']) : ?> · <?= t('starts %s', h($t['startsAtLabel'])) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-<?= $stateClass[$t['phase']] ?> fs-6"><?= h($t['stateLabel']) ?></span>
                            <div><a href="<?= h($t['url']) ?>" target="_blank" rel="noopener" class="small"><?= t('on Challonge') ?> <i class="fas fa-external-link-alt"></i></a></div>
                        </div>
                    </header>
                    <?php if ($t['description']) : ?><p class="ct-description"><?= nl2br(h($t['description'])) ?></p><?php endif; ?>

                    <?php if ($options['showMatches'] && $t['matches'] !== []) : ?>
                        <section class="ct-section ct-matches mb-4">
                            <?php foreach (['live' => t('Live now'), 'upcoming' => t('Upcoming'), 'completed' => t('Finished')] as $section => $label) :
                                $ids = $t['sections'][$section];
                                if ($ids === []) {
                                    continue;
                                } ?>
                                <h4 class="ct-section-title h5 mt-3"><?= h($label) ?> <span class="badge bg-light text-dark"><?= count($ids) ?></span></h4>
                                <div class="list-group ct-match-list ct-<?= $section ?>">
                                    <?php foreach ($ids as $id) {
                                        $renderMatch($byID[$id], $t);
                                    } ?>
                                </div>
                            <?php endforeach; ?>
                        </section>
                    <?php elseif ($options['showMatches'] && $t['phase'] === 'pending') : ?>
                        <p class="text-muted"><?= t('The matches appear once the tournament is started.') ?></p>
                    <?php endif; ?>

                    <div class="row g-4">
                        <?php if ($options['showStandings'] && $t['standings'] !== [] && $t['phase'] !== 'pending') : ?>
                            <div class="col-lg-6">
                                <section class="ct-section ct-standings">
                                    <h4 class="h5"><?= $t['phase'] === 'complete' ? t('Final standings') : t('Standings') ?></h4>
                                    <table class="table table-sm ct-standings-table">
                                        <thead><tr><th>#</th><th><?= t('Team') ?></th><th class="text-end"><?= t('W') ?></th><th class="text-end"><?= t('L') ?></th><th class="text-end" title="<?= t('Map / set difference') ?>">+/−</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($t['standings'] as $row) : ?>
                                            <tr class="<?= $row['participant']['mine'] ? 'ct-mine' : '' ?>">
                                                <td><?= $row['rank'] === 1 && $t['phase'] === 'complete' ? '<i class="fas fa-crown text-warning"></i>' : (int) $row['rank'] ?></td>
                                                <td><?= h($row['participant']['name']) ?></td>
                                                <td class="text-end"><?= (int) $row['wins'] ?></td>
                                                <td class="text-end"><?= (int) $row['losses'] ?></td>
                                                <td class="text-end"><?= $row['diff'] > 0 ? '+' : '' ?><?= (int) $row['diff'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </section>
                            </div>
                        <?php endif; ?>
                        <?php if ($options['showParticipants'] && $t['participants'] !== []) : ?>
                            <div class="col-lg-6">
                                <section class="ct-section ct-participants">
                                    <h4 class="h5"><?= t('Participants') ?></h4>
                                    <ol class="ct-participant-list">
                                        <?php foreach ($t['participants'] as $p) : ?>
                                            <li class="<?= $p['mine'] ? 'ct-mine' : '' ?> <?= $p['active'] ? '' : 'text-muted' ?>"><?= h($p['name']) ?><?php if ($p['mine']) : ?> <span class="badge bg-info text-dark"><?= t('your team') ?></span><?php endif; ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                </section>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($options['showBracket'] && $t['phase'] !== 'pending') : ?>
                        <section class="ct-section ct-bracket mt-4">
                            <h4 class="h5"><?= t('Bracket') ?></h4>
                            <div class="ct-bracket-frame">
                                <iframe src="<?= h($t['embedUrl']) ?>" width="100%" height="<?= (int) $options['bracketHeight'] ?>" frameborder="0" scrolling="auto" loading="lazy" allowtransparency="true" title="<?= h($t['name']) ?>"></iframe>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php if (!$t['permissions']['signedIn'] && $t['phase'] === 'underway' && $t['permissions']['reportingEnabled']) : ?>
                        <p class="small text-muted mt-3"><a href="<?= h($loginURL) ?>"><?= t('Sign in') ?></a> <?= t('to report the results of your team.') ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
