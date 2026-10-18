<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Built-in UI (Bootstrap 5). Variables: $entries ([{tournament, participant, matches}]), $title, $user, $token,
 * $tokenName, $actionBase, $returnURL, $loginURL, $messages, $bID.
 *
 * @var array[] $entries
 * @var string $title
 * @var \Concrete\Core\User\User|null $user
 * @var \Concrete\Core\Validation\CSRF\Token $token
 * @var string $tokenName
 * @var string $actionBase
 * @var string $returnURL
 * @var string $loginURL
 * @var array{success: string[], error: string[]} $messages
 * @var int $bID
 */
?>
<div class="ct-block ct-my-matches" id="ct-my-<?= (int) $bID ?>">
    <?php if ($title !== '') : ?><h3 class="ct-title"><?= h($title) ?></h3><?php endif; ?>
    <?php foreach (['success' => 'success', 'error' => 'danger'] as $type => $class) :
        foreach ($messages[$type] as $message) : ?>
            <div class="alert alert-<?= $class ?> alert-dismissible"><?= h($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endforeach;
    endforeach; ?>
    <div class="ct-messages"></div>

    <?php if ($user === null) : ?>
        <p class="text-muted"><a href="<?= h($loginURL) ?>"><?= t('Sign in') ?></a> <?= t('to see your matches.') ?></p>
    <?php elseif ($entries === []) : ?>
        <p class="text-muted ct-empty"><?= t('You are not playing in a running tournament.') ?></p>
    <?php else : ?>
        <?php foreach ($entries as $entry) :
            $t = $entry['tournament'];
            $me = $entry['participant'];
            ?>
            <div class="ct-my-tournament mb-4">
                <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2">
                    <h4 class="h5 mb-1"><?= h($t['name']) ?> <span class="text-muted small"><?= t('as %s', h($me['name'])) ?></span></h4>
                    <a href="<?= h($t['url']) ?>" target="_blank" rel="noopener" class="small"><?= t('on Challonge') ?> <i class="fas fa-external-link-alt"></i></a>
                </div>
                <?php if ($entry['matches'] === []) : ?>
                    <p class="text-muted small"><?= $t['phase'] === 'pending' ? t('The tournament has not started yet.') : t('No match for you right now.') ?></p>
                <?php else : ?>
                    <div class="list-group ct-match-list">
                        <?php foreach ($entry['matches'] as $m) :
                            $mySide = ($m['p1']['id'] ?? null) === $me['id'] ? 1 : ((($m['p2']['id'] ?? null) === $me['id']) ? 2 : null);
                            $opponent = $mySide === 1 ? ($m['p2']['name'] ?? null) : ($m['p1']['name'] ?? null);
                            $won = $m['winnerSide'] !== null ? $m['winnerSide'] === $mySide : null;
                            ?>
                            <div class="list-group-item ct-match <?= $m['underway'] ? 'ct-match-live' : '' ?> <?= $m['state'] === 'complete' ? 'ct-match-complete' : '' ?>">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <span class="text-muted small"><?= h((string) $m['identifier']) ?> · <?= h($m['roundLabel']) ?></span>
                                        <?php if ($m['underway']) : ?><span class="badge bg-danger ms-1"><i class="fas fa-circle ct-live-dot"></i> <?= t('Live') ?></span><?php endif; ?>
                                        <div>
                                            <?= t('vs') ?> <strong><?= $opponent !== null ? h($opponent) : '<em class="text-muted">' . t('tbd') . '</em>' ?></strong>
                                            <?php if ($m['setWins'] !== null) : ?>
                                                <span class="badge <?= $won === true ? 'bg-success' : ($won === false ? 'bg-danger' : 'bg-light text-dark') ?> ms-2"><?= $mySide === 1 ? (int) $m['setWins'][0] . ' : ' . (int) $m['setWins'][1] : (int) $m['setWins'][1] . ' : ' . (int) $m['setWins'][0] ?></span>
                                                <?php if ($won === true) : ?><span class="small text-success"><?= t('won') ?></span><?php elseif ($won === false) : ?><span class="small text-danger"><?= t('lost') ?></span><?php endif; ?>
                                            <?php elseif ($m['state'] === 'pending') : ?>
                                                <span class="small text-muted"><?= t('waiting for the previous matches') ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap align-items-center">
                                        <?php if ($m['pending']) : ?>
                                            <span class="badge bg-warning text-dark" title="<?= h($m['pending']['note'] ?? '') ?>"><i class="fas fa-flag"></i> <?= t('%1$s wins %2$s', h($m['pending']['winnerName']), h($m['pending']['scores'])) ?></span>
                                            <?php if ($m['pending']['canConfirm']) : ?>
                                                <form method="post" action="<?= h($actionBase . '/confirm/' . (int) $m['pending']['id']) ?>" class="ct-ajax"><?php $token->output($tokenName) ?><input type="hidden" name="return" value="<?= h($returnURL) ?>"><button class="btn btn-sm btn-success"><i class="fas fa-check"></i> <?= t('Confirm') ?></button></form>
                                                <form method="post" action="<?= h($actionBase . '/reject/' . (int) $m['pending']['id']) ?>" class="ct-ajax"><?php $token->output($tokenName) ?><input type="hidden" name="return" value="<?= h($returnURL) ?>"><button class="btn btn-sm btn-outline-danger"><?= t('Disagree') ?></button></form>
                                            <?php elseif ($m['pending']['mine']) : ?>
                                                <form method="post" action="<?= h($actionBase . '/withdraw/' . (int) $m['pending']['id']) ?>" class="ct-ajax"><?php $token->output($tokenName) ?><input type="hidden" name="return" value="<?= h($returnURL) ?>"><button class="btn btn-sm btn-outline-secondary"><?= t('Withdraw') ?></button></form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($m['canToggleUnderway']) : ?>
                                            <form method="post" action="<?= h($actionBase . '/report/' . (int) $m['id']) ?>" class="ct-ajax"><?php $token->output($tokenName) ?><input type="hidden" name="return" value="<?= h($returnURL) ?>"><input type="hidden" name="underway" value="<?= $m['underway'] ? 0 : 1 ?>"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-<?= $m['underway'] ? 'pause' : 'play' ?>"></i> <?= $m['underway'] ? t('Not live anymore') : t('We are playing') ?></button></form>
                                        <?php endif; ?>
                                        <?php if ($m['canReport'] && (!$m['pending'] || (!$m['pending']['mine'] && !$m['pending']['canConfirm']))) : ?>
                                            <button type="button" class="btn btn-sm btn-primary ct-toggle-report" data-target="#ct-my-report-<?= (int) $bID ?>-<?= (int) $m['id'] ?>"><i class="fas fa-flag-checkered"></i> <?= t('Report result') ?></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($m['canReport']) : ?>
                                    <form method="post" action="<?= h($actionBase . '/report/' . (int) $m['id']) ?>" class="ct-ajax ct-report-form mt-2" id="ct-my-report-<?= (int) $bID ?>-<?= (int) $m['id'] ?>" style="display:none">
                                        <?php $token->output($tokenName) ?>
                                        <input type="hidden" name="return" value="<?= h($returnURL) ?>">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-sm-5">
                                                <label class="form-label small mb-1"><?= t('Winner') ?></label>
                                                <select name="winner" class="form-select form-select-sm" required>
                                                    <option value="1" <?= $mySide === 1 ? 'selected' : '' ?>><?= h($m['p1']['name']) ?></option>
                                                    <option value="2" <?= $mySide === 2 ? 'selected' : '' ?>><?= h($m['p2']['name']) ?></option>
                                                </select>
                                            </div>
                                            <div class="col-sm-5">
                                                <label class="form-label small mb-1"><?= t('Scores (%s first)', h($m['p1']['name'])) ?></label>
                                                <input type="text" name="scores" class="form-control form-control-sm" placeholder="16-9,10-16,16-12" required pattern="\s*-?\d+\s*-\s*-?\d+(\s*,\s*-?\d+\s*-\s*-?\d+)*\s*">
                                            </div>
                                            <div class="col-sm-2"><button type="submit" class="btn btn-sm btn-primary w-100"><?= t('Send') ?></button></div>
                                        </div>
                                        <div class="form-text"><?= t('One score per map, separated by commas. The other team or a moderator confirms before it counts.') ?></div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
