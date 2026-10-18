<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Dashboard › Tournaments. Variables: $mode (list|add|tournament|settings), $token, $tokenAction, $basePath,
 * $presenter, and per mode what the controller set.
 *
 * @var \Concrete\Core\Validation\CSRF\Token $token
 * @var string $tokenAction
 * @var string $basePath
 * @var \ChallongeTournaments\Tournament\Presenter $presenter
 * @var \Concrete\Core\Page\View\PageView $view
 */
$url = static function (string $path = '') use ($basePath) {
    return \Concrete\Core\Support\Facade\Url::to($basePath . ($path !== '' ? '/' . ltrim($path, '/') : ''));
};
$stateBadge = static function (string $state): string {
    $classes = [
        'pending' => 'bg-secondary', 'checking_in' => 'bg-info', 'checked_in' => 'bg-info', 'accepting_predictions' => 'bg-info',
        'group_stages_underway' => 'bg-primary', 'group_stages_finalized' => 'bg-primary', 'underway' => 'bg-primary',
        'awaiting_review' => 'bg-warning text-dark', 'complete' => 'bg-success',
    ];

    return '<span class="badge ' . ($classes[$state] ?? 'bg-secondary') . '">' . h(\ChallongeTournaments\Tournament\Presenter::stateLabel($state)) . '</span>';
};
$form = static function (string $action, string $buttonLabel, string $buttonClass = 'btn btn-sm btn-outline-secondary', array $hidden = [], ?string $confirm = null) use ($token, $tokenAction) {
    echo '<form method="post" class="d-inline" action="' . h($action) . '"' . ($confirm ? ' onsubmit="return confirm(' . h(json_encode($confirm)) . ')"' : '') . '>';
    $token->output($tokenAction);
    foreach ($hidden as $name => $value) {
        echo '<input type="hidden" name="' . h($name) . '" value="' . h((string) $value) . '">';
    }
    echo '<button type="submit" class="' . h($buttonClass) . '">' . $buttonLabel . '</button></form>';
};
?>

<?php if ($mode === 'list') : ?>
    <?php
    /** @var array<int, \ChallongeTournaments\Entity\Tournament[]> $tournamentsByEvent */
    /** @var array<int, string> $eventNames */
    /** @var \ChallongeTournaments\Entity\ScoreReport[] $pendingReports */
    /** @var \ChallongeTournaments\Api\ClientInterface $client */
    /** @var \ChallongeTournaments\Config\Settings $settings */
    ?>
    <div class="ccm-dashboard-header-buttons">
        <a href="<?= h($url('settings')) ?>" class="btn btn-secondary"><i class="fas fa-cog"></i> <?= t('Settings') ?></a>
        <a href="<?= h($url('add')) ?>" class="btn btn-primary"><i class="fas fa-plus"></i> <?= t('Link tournament') ?></a>
    </div>

    <?php if (!$client->isLive()) : ?>
        <div class="alert alert-warning">
            <strong><?= t('Fake Challonge active.') ?></strong>
            <?= t('No API key is configured (or the fake client is forced in the settings): tournaments come from the built-in fake Challonge, nothing reaches challonge.com.') ?>
            <a href="<?= h($url('settings')) ?>" class="alert-link"><?= t('Settings') ?></a>
        </div>
    <?php endif; ?>

    <?php if ($pendingReports !== []) : ?>
        <div class="alert alert-info">
            <i class="fas fa-flag"></i>
            <?= t2('%d result report waits for confirmation.', '%d result reports wait for confirmation.', count($pendingReports)) ?>
            <?php $byTournament = [];
            foreach ($pendingReports as $r) {
                $byTournament[$r->getTournament()->getID()] = $r->getTournament();
            }
            foreach ($byTournament as $t) : ?>
                <a href="<?= h($url('tournament/' . $t->getID())) ?>#reports" class="alert-link ms-2"><?= h($t->getName()) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tournamentsByEvent === []) : ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-trophy fa-3x mb-3"></i>
            <p><?= t('No tournament is linked yet.') ?></p>
            <a href="<?= h($url('add')) ?>" class="btn btn-primary"><?= t('Link the first tournament') ?></a>
        </div>
    <?php else : ?>
        <?php krsort($tournamentsByEvent);
        foreach ($tournamentsByEvent as $eventID => $tournaments) : ?>
            <h4 class="mt-4"><?= $eventID ? h($eventNames[$eventID] ?? t('Event #%d', $eventID)) : t('Without event') ?></h4>
            <table class="table table-striped align-middle">
                <thead>
                <tr>
                    <th><?= t('Tournament') ?></th>
                    <th><?= t('Game') ?></th>
                    <th><?= t('Type') ?></th>
                    <th><?= t('State') ?></th>
                    <th class="text-end"><?= t('Participants') ?></th>
                    <th><?= t('Last sync') ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tournaments as $t) : ?>
                    <tr>
                        <td>
                            <a href="<?= h($url('tournament/' . $t->getID())) ?>"><strong><?= h($t->getName()) ?></strong></a>
                            <?php if (!$t->isVisible()) : ?><span class="badge bg-light text-dark ms-1"><?= t('hidden') ?></span><?php endif; ?>
                            <?php if ($t->getLastSyncError()) : ?><i class="fas fa-exclamation-triangle text-danger ms-1" title="<?= h($t->getLastSyncError()) ?>"></i><?php endif; ?>
                            <div class="small text-muted"><?= h($t->getChallongeURL()) ?></div>
                        </td>
                        <td><?= h((string) $t->getGameName()) ?></td>
                        <td><?= h(\ChallongeTournaments\Tournament\Presenter::typeLabel($t->getTournamentType())) ?></td>
                        <td><?= $stateBadge($t->getState()) ?></td>
                        <td class="text-end"><?= (int) $t->getParticipantsCount() ?></td>
                        <td class="small text-muted"><?= $t->getLastSyncedAt() ? h($t->getLastSyncedAt()->format('d.m.Y H:i')) : t('never') ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= h($t->getChallongeURL()) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="<?= t('Open on Challonge') ?>"><i class="fas fa-external-link-alt"></i></a>
                            <a href="<?= h($url('tournament/' . $t->getID())) ?>" class="btn btn-sm btn-primary"><?= t('Manage') ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>

<?php elseif ($mode === 'add') : ?>
    <?php
    /** @var array<int, string> $events */
    /** @var array<int, string> $pools */
    /** @var string[] $fakeSlugs */
    /** @var \ChallongeTournaments\Config\Settings $settings */
    ?>
    <div class="ccm-dashboard-header-buttons">
        <a href="<?= h($url()) ?>" class="btn btn-secondary"><?= t('Back') ?></a>
    </div>
    <form method="post" action="<?= h($url('link')) ?>">
        <?php $token->output($tokenAction) ?>
        <div class="row">
            <div class="col-lg-8">
                <div class="mb-3">
                    <label class="form-label" for="ct-reference"><?= t('Challonge URL or ID') ?></label>
                    <input type="text" class="form-control" id="ct-reference" name="reference" required placeholder="https://challonge.com/lan35_cs2" autofocus>
                    <div class="form-text"><?= t('Create the tournament on challonge.com first (type, game, rules, format). Paste its URL here; the participants, matches and state are then read from Challonge.') ?></div>
                    <?php if ($fakeSlugs !== []) : ?>
                        <div class="form-text"><?= t('Fake Challonge tournaments:') ?>
                            <?php foreach ($fakeSlugs as $slug) : ?>
                                <a href="#" class="ct-fill-reference me-2" data-reference="<?= h($slug) ?>"><code><?= h($slug) ?></code></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="ct-community"><?= t('Community (subdomain)') ?></label>
                    <input type="text" class="form-control" id="ct-community" name="community" value="<?= h($settings->getCommunity()) ?>" placeholder="turicane">
                    <div class="form-text"><?= t('Only for tournaments of a Challonge community (name.challonge.com). A full URL overrides this.') ?></div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="ct-event"><?= t('Event') ?></label>
                        <select class="form-select" id="ct-event" name="eventID">
                            <?php foreach ($events as $id => $label) : ?>
                                <option value="<?= (int) $id ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= t('The event pages show the tournaments of their event.') ?></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="ct-pool"><?= t('Team pool') ?></label>
                        <select class="form-select" id="ct-pool" name="poolID">
                            <?php foreach ($pools as $id => $label) : ?>
                                <option value="<?= (int) $id ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= t('The teams (and singles) of this pool can be seeded into the tournament.') ?></div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-link"></i> <?= t('Link tournament') ?></button>
            </div>
        </div>
    </form>

<?php elseif ($mode === 'tournament') : ?>
    <?php
    /** @var \ChallongeTournaments\Entity\Tournament $tournament */
    /** @var array $data */
    /** @var array<int, string> $events */
    /** @var array<int, string> $pools */
    /** @var array<int, string> $groups */
    /** @var bool $seedingAvailable */
    /** @var \ChallongeTournaments\Seeding\Candidate[] $candidates */
    /** @var array<string, string> $transitions */
    /** @var \ChallongeTournaments\Entity\ScoreReport[] $pendingReports */
    /** @var \ChallongeTournaments\Entity\ScoreReport[] $recentReports */
    /** @var array<int, string> $userNames */
    /** @var \ChallongeTournaments\Api\ClientInterface $client */
    $tid = $tournament->getID();
    $participantsByID = [];
    foreach ($data['participants'] as $p) {
        $participantsByID[$p['id']] = $p;
    }
    ?>
    <div class="ccm-dashboard-header-buttons">
        <a href="<?= h($url()) ?>" class="btn btn-secondary"><?= t('Back') ?></a>
        <a href="<?= h($tournament->getChallongeURL()) ?>" target="_blank" rel="noopener" class="btn btn-secondary"><i class="fas fa-external-link-alt"></i> <?= t('Open on Challonge') ?></a>
        <?php $form($url('sync/' . $tid), '<i class="fas fa-sync"></i> ' . t('Sync now'), 'btn btn-primary'); ?>
    </div>

    <?php if (!$client->isLive()) : ?>
        <div class="alert alert-warning py-2"><strong><?= t('Fake Challonge active') ?></strong> — <?= t('nothing reaches challonge.com.') ?></div>
    <?php endif; ?>
    <?php if ($tournament->getLastSyncError()) : ?>
        <div class="alert alert-danger"><strong><?= t('Last sync failed:') ?></strong> <?= h($tournament->getLastSyncError()) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <h3 class="mb-1"><?= h($tournament->getName()) ?></h3>
                            <div class="text-muted">
                                <?= h((string) $tournament->getGameName()) ?> · <?= h($data['typeLabel']) ?>
                                <?php if ($data['startsAtLabel']) : ?> · <?= t('starts %s', h($data['startsAtLabel'])) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <?= $stateBadge($tournament->getState()) ?>
                            <div class="small text-muted mt-1">
                                <?= t('synced %s', $tournament->getLastSyncedAt() ? h($tournament->getLastSyncedAt()->format('d.m.Y H:i:s')) : t('never')) ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($transitions !== []) : ?>
                        <hr>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="text-muted small me-2"><?= t('State on Challonge:') ?></span>
                            <?php foreach ($transitions as $state => $label) :
                                $danger = in_array($state, ['reset', 'reset_group_stage', 'abort_checkin'], true);
                                $form($url('change_state/' . $tid), h($label), 'btn btn-sm ' . ($danger ? 'btn-outline-danger' : 'btn-outline-primary'), ['state' => $state], $danger ? t('Really? "%s" cannot be undone on Challonge.', $label) : ($state === 'start' ? t('Start the tournament on Challonge? Participants are locked afterwards.') : ($state === 'finalize' ? t('Finalize the tournament on Challonge?') : null)));
                            endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* ---- participants & seeding ---- */ ?>
            <div class="card mb-4" id="participants">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><?= t('Participants') ?> <span class="badge bg-secondary"><?= count($data['participants']) ?></span></strong>
                    <?php if ($tournament->isPending() && $data['participants'] !== []) : ?>
                        <div>
                            <?php $form($url('shuffle/' . $tid), '<i class="fas fa-random"></i> ' . t('Shuffle seeds')); ?>
                            <?php $form($url('clear_participants/' . $tid), '<i class="fas fa-trash"></i> ' . t('Remove all'), 'btn btn-sm btn-outline-danger', [], t('Remove all participants from the tournament on Challonge?')); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($data['participants'] === []) : ?>
                    <div class="card-body text-muted"><?= t('No participants on Challonge yet.') ?></div>
                <?php else : ?>
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th style="width:4rem"><?= t('Seed') ?></th><th><?= t('Name') ?></th><th><?= t('Linked to') ?></th><?php if ($tournament->isComplete()) : ?><th><?= t('Rank') ?></th><?php endif; ?><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($data['participants'] as $p) : ?>
                            <tr class="<?= $p['active'] ? '' : 'text-muted' ?>">
                                <td>
                                    <?php if ($tournament->isPending()) : ?>
                                        <form method="post" action="<?= h($url('reseed/' . $tid . '/' . $p['id'])) ?>" class="d-flex">
                                            <?php $token->output($tokenAction) ?>
                                            <input type="number" name="seed" value="<?= (int) $p['seed'] ?>" min="1" max="<?= count($data['participants']) ?>" class="form-control form-control-sm ct-seed-input" onchange="this.form.submit()" style="width:4rem">
                                        </form>
                                    <?php else : ?>
                                        <?= (int) $p['seed'] ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($p['name']) ?><?php if (!$p['active']) : ?> <span class="badge bg-light text-dark"><?= t('inactive') ?></span><?php endif; ?></td>
                                <td class="small text-muted">
                                    <?php if ($p['teamID']) : ?><i class="fas fa-shield-alt"></i> <?= t('Team #%d', $p['teamID']) ?>
                                    <?php elseif ($p['userID']) : ?><i class="fas fa-user"></i> <?= t('User #%d', $p['userID']) ?>
                                    <?php else : ?>—<?php endif; ?>
                                </td>
                                <?php if ($tournament->isComplete()) : ?><td><?= $p['finalRank'] !== null ? (int) $p['finalRank'] . '.' : '' ?></td><?php endif; ?>
                                <td class="text-end">
                                    <?php if ($tournament->isPending()) : ?>
                                        <?php $form($url('remove_participant/' . $tid . '/' . $p['id']), '<i class="fas fa-times"></i>', 'btn btn-sm btn-outline-danger', [], t('Remove "%s" from the tournament?', $p['name'])); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if ($tournament->isPending()) : ?>
                <div class="card mb-4" id="seeding">
                    <div class="card-header"><strong><?= t('Seed from the team pool') ?></strong></div>
                    <div class="card-body">
                        <?php if (!$seedingAvailable) : ?>
                            <p class="text-muted mb-0"><?= t('The Team Manager package is not installed.') ?></p>
                        <?php elseif ($tournament->getPoolID() === null) : ?>
                            <p class="text-muted mb-0"><?= t('Choose a team pool in the settings on the right first.') ?></p>
                        <?php else :
                            $open = array_filter($candidates, static function ($c) {
                                return !$c->isSeeded() && !$c->isOrphan();
                            });
                            $orphans = array_filter($candidates, static function ($c) {
                                return $c->isOrphan();
                            });
                            ?>
                            <?php if ($open === []) : ?>
                                <p class="text-muted mb-0"><?= t('Every team of the pool is in the tournament.') ?></p>
                            <?php else : ?>
                                <form method="post" action="<?= h($url('seed/' . $tid)) ?>" class="ct-seed-form">
                                    <?php $token->output($tokenAction) ?>
                                    <p class="text-muted small"><?= t('Tick the teams to add, drag to set the order (the seeds continue after the existing participants) or let the order be random.') ?></p>
                                    <ul class="list-group mb-3 ct-sortable">
                                        <?php foreach ($open as $c) : ?>
                                            <li class="list-group-item d-flex align-items-center gap-2" draggable="true">
                                                <span class="text-muted ct-handle" title="<?= t('Drag to reorder') ?>"><i class="fas fa-grip-vertical"></i></span>
                                                <input class="form-check-input mt-0" type="checkbox" name="keys[]" value="<?= h($c->getKey()) ?>" checked id="ct-c-<?= h(md5($c->getKey())) ?>">
                                                <label class="form-check-label flex-grow-1" for="ct-c-<?= h(md5($c->getKey())) ?>">
                                                    <i class="fas <?= $c->isTeam() ? 'fa-shield-alt' : 'fa-user' ?> text-muted"></i>
                                                    <strong><?= h($c->getName()) ?></strong>
                                                    <?php if ($c->getMembers() !== []) : ?><span class="text-muted small ms-2"><?= h(implode(', ', $c->getMembers())) ?></span><?php endif; ?>
                                                </label>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <div class="d-flex gap-3 align-items-center flex-wrap">
                                        <div class="form-check"><input class="form-check-input" type="radio" name="order" value="random" id="ct-order-random" checked><label class="form-check-label" for="ct-order-random"><?= t('Random order') ?></label></div>
                                        <div class="form-check"><input class="form-check-input" type="radio" name="order" value="manual" id="ct-order-manual"><label class="form-check-label" for="ct-order-manual"><?= t('Order as listed') ?></label></div>
                                        <button type="submit" class="btn btn-primary ms-auto"><i class="fas fa-upload"></i> <?= t('Add to Challonge') ?></button>
                                    </div>
                                </form>
                            <?php endif; ?>
                            <?php if ($orphans !== []) : ?>
                                <div class="alert alert-warning mt-3 mb-0 small">
                                    <?= t('On Challonge but not in the pool:') ?>
                                    <?php foreach ($orphans as $c) : ?><span class="badge bg-warning text-dark me-1"><?= h($c->getName()) ?></span><?php endforeach; ?>
                                    <?= t('Remove them above if they should not play.') ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php /* ---- matches ---- */ ?>
            <?php if ($data['matches'] !== []) : ?>
                <div class="card mb-4" id="matches">
                    <div class="card-header"><strong><?= t('Matches') ?></strong> <span class="text-muted small ms-2"><?= t('%1$d live, %2$d upcoming, %3$d finished', count($data['sections']['live']), count($data['sections']['upcoming']), count($data['sections']['completed'])) ?></span></div>
                    <table class="table table-sm mb-0 align-middle ct-matches">
                        <thead><tr><th><?= t('Match') ?></th><th><?= t('Round') ?></th><th><?= t('Sides') ?></th><th><?= t('Result') ?></th><th><?= t('State') ?></th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($data['matches'] as $m) :
                            if ($m['p1'] === null && $m['p2'] === null && $m['state'] !== 'open') {
                                continue;
                            } ?>
                            <tr class="<?= $m['underway'] ? 'table-primary' : '' ?>">
                                <td><strong><?= h((string) $m['identifier']) ?></strong><?php if ($m['order']) : ?> <span class="text-muted small">#<?= (int) $m['order'] ?></span><?php endif; ?></td>
                                <td class="small"><?= h($m['roundLabel']) ?></td>
                                <td>
                                    <span class="<?= $m['winnerSide'] === 1 ? 'fw-bold' : '' ?>"><?= $m['p1'] ? h($m['p1']['name']) : '<em class="text-muted">' . t('tbd') . '</em>' ?></span>
                                    <span class="text-muted mx-1"><?= t('vs') ?></span>
                                    <span class="<?= $m['winnerSide'] === 2 ? 'fw-bold' : '' ?>"><?= $m['p2'] ? h($m['p2']['name']) : '<em class="text-muted">' . t('tbd') . '</em>' ?></span>
                                </td>
                                <td>
                                    <?php if ($m['scores']) : ?><code><?= h($m['scores']) ?></code><?php endif; ?>
                                    <?php if ($m['pending']) : ?>
                                        <span class="badge bg-warning text-dark ms-1" title="<?= h($m['pending']['note'] ?? '') ?>"><?= t('reported: %1$s wins %2$s', h($m['pending']['winnerName']), h($m['pending']['scores'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= h(\ChallongeTournaments\Tournament\Presenter::matchStateLabel($m)) ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($m['state'] === 'open' && $m['p1'] && $m['p2']) : ?>
                                        <?php $form($url('report_match/' . $tid . '/' . $m['id']), $m['underway'] ? t('Not live') : t('Live'), 'btn btn-sm btn-outline-secondary', ['underway' => $m['underway'] ? 0 : 1]); ?>
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#ct-report-<?= (int) $m['id'] ?>"><?= t('Report') ?></button>
                                    <?php elseif ($m['state'] === 'complete') : ?>
                                        <?php $form($url('report_match/' . $tid . '/' . $m['id']), t('Reopen'), 'btn btn-sm btn-outline-danger', ['reopen' => 1], t('Reopen match %s on Challonge? Following matches lose this result.', $m['identifier'])); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($m['state'] === 'open' && $m['p1'] && $m['p2']) : ?>
                                <tr class="collapse" id="ct-report-<?= (int) $m['id'] ?>">
                                    <td colspan="6" class="bg-light">
                                        <form method="post" action="<?= h($url('report_match/' . $tid . '/' . $m['id'])) ?>" class="row g-2 align-items-end">
                                            <?php $token->output($tokenAction) ?>
                                            <div class="col-md-4">
                                                <label class="form-label small"><?= t('Winner') ?></label>
                                                <select name="winner" class="form-select form-select-sm" required>
                                                    <option value=""><?= t('— choose —') ?></option>
                                                    <option value="1"><?= h($m['p1']['name']) ?></option>
                                                    <option value="2"><?= h($m['p2']['name']) ?></option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small"><?= t('Scores (%s first)', h($m['p1']['name'])) ?></label>
                                                <input type="text" name="scores" class="form-control form-control-sm" placeholder="16-9,10-16,16-12" required pattern="\s*-?\d+\s*-\s*-?\d+(\s*,\s*-?\d+\s*-\s*-?\d+)*\s*">
                                            </div>
                                            <div class="col-md-4">
                                                <button type="submit" class="btn btn-sm btn-primary"><?= t('Write to Challonge') ?></button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php /* ---- reports ---- */ ?>
            <div class="card mb-4" id="reports">
                <div class="card-header"><strong><?= t('Result reports') ?></strong> <?php if ($pendingReports !== []) : ?><span class="badge bg-warning text-dark"><?= count($pendingReports) ?> <?= t('pending') ?></span><?php endif; ?></div>
                <?php if ($recentReports === []) : ?>
                    <div class="card-body text-muted"><?= t('No results were reported on the site yet.') ?></div>
                <?php else : ?>
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th><?= t('When') ?></th><th><?= t('Match') ?></th><th><?= t('Reported') ?></th><th><?= t('By') ?></th><th><?= t('Status') ?></th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($recentReports as $r) :
                            $m = $r->getMatch();
                            $winner = $r->getWinner();
                            $statusClass = ['pending' => 'bg-warning text-dark', 'applied' => 'bg-success', 'rejected' => 'bg-danger', 'withdrawn' => 'bg-secondary', 'failed' => 'bg-danger', 'superseded' => 'bg-light text-dark'];
                            ?>
                            <tr>
                                <td class="small text-nowrap"><?= h($r->getCreatedAt()->format('d.m. H:i')) ?></td>
                                <td><strong><?= h((string) $m->getIdentifier()) ?></strong> <span class="small text-muted"><?= $m->getPlayer1() ? h($m->getPlayer1()->getName()) : '?' ?> <?= t('vs') ?> <?= $m->getPlayer2() ? h($m->getPlayer2()->getName()) : '?' ?></span></td>
                                <td><?= $winner ? h($winner->getName()) : '?' ?> <?= t('wins') ?> <code><?= h($r->getScoresCsv()) ?></code><?php if ($r->getNote()) : ?><div class="small text-muted"><?= h($r->getNote()) ?></div><?php endif; ?></td>
                                <td class="small"><?= h($userNames[$r->getReportedBy()] ?? '#' . $r->getReportedBy()) ?><?php if ($r->getReporterSide()) : ?> <span class="text-muted">(<?= t('side %d', $r->getReporterSide()) ?>)</span><?php endif; ?></td>
                                <td>
                                    <span class="badge <?= $statusClass[$r->getStatus()] ?? 'bg-secondary' ?>"><?= h($r->getStatus()) ?></span>
                                    <?php if ($r->getError()) : ?><div class="small text-danger"><?= h($r->getError()) ?></div><?php endif; ?>
                                    <?php if ($r->getResolvedBy()) : ?><div class="small text-muted"><?= h($userNames[$r->getResolvedBy()] ?? '#' . $r->getResolvedBy()) ?></div><?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ($r->isPending()) : ?>
                                        <?php $form($url('confirm_report/' . $tid . '/' . $r->getID()), '<i class="fas fa-check"></i> ' . t('Confirm'), 'btn btn-sm btn-success'); ?>
                                        <?php $form($url('reject_report/' . $tid . '/' . $r->getID()), '<i class="fas fa-times"></i> ' . t('Reject'), 'btn btn-sm btn-outline-danger'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header"><strong><?= t('Settings') ?></strong></div>
                <div class="card-body">
                    <form method="post" action="<?= h($url('save_tournament/' . $tid)) ?>">
                        <?php $token->output($tokenAction) ?>
                        <div class="mb-3">
                            <label class="form-label" for="ct-event"><?= t('Event') ?></label>
                            <select class="form-select" id="ct-event" name="eventID">
                                <?php foreach ($events as $id => $label) : ?>
                                    <option value="<?= (int) $id ?>" <?= (int) $id === (int) $tournament->getEventID() ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                                <?php if ($tournament->getEventID() && !isset($events[$tournament->getEventID()])) : ?>
                                    <option value="<?= (int) $tournament->getEventID() ?>" selected><?= h($eventNames[$tournament->getEventID()] ?? t('Event #%d', $tournament->getEventID())) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ct-pool"><?= t('Team pool') ?></label>
                            <select class="form-select" id="ct-pool" name="poolID">
                                <?php foreach ($pools as $id => $label) : ?>
                                    <option value="<?= (int) $id ?>" <?= (int) $id === (int) $tournament->getPoolID() ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ct-modgroup"><?= t('Moderator group') ?></label>
                            <select class="form-select" id="ct-modgroup" name="moderatorGroupID">
                                <?php foreach ($groups as $id => $label) : ?>
                                    <option value="<?= (int) $id ?>" <?= (int) $id === (int) $tournament->getModeratorGroupID() ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text"><?= t('Its members confirm reports and may report any match. Administrators always can.') ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ct-position"><?= t('Position within the event') ?></label>
                            <input type="number" class="form-control" id="ct-position" name="position" value="<?= (int) $tournament->getPosition() ?>">
                        </div>
                        <div class="form-check mb-2">
                            <input type="hidden" name="visible" value="0">
                            <input class="form-check-input" type="checkbox" name="visible" value="1" id="ct-visible" <?= $tournament->isVisible() ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ct-visible"><?= t('Show on the site') ?></label>
                        </div>
                        <div class="form-check mb-3">
                            <input type="hidden" name="reportingEnabled" value="0">
                            <input class="form-check-input" type="checkbox" name="reportingEnabled" value="1" id="ct-reporting" <?= $tournament->isReportingEnabled() ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ct-reporting"><?= t('Captains may report results') ?></label>
                        </div>
                        <button type="submit" class="btn btn-primary"><?= t('Save') ?></button>
                    </form>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><strong><?= t('Challonge') ?></strong></div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-5"><?= t('ID') ?></dt><dd class="col-7"><code><?= h($tournament->getChallongeID()) ?></code></dd>
                        <dt class="col-5"><?= t('URL') ?></dt><dd class="col-7"><a href="<?= h($tournament->getChallongeURL()) ?>" target="_blank" rel="noopener"><?= h($tournament->getSlug()) ?></a></dd>
                        <?php if ($tournament->getCommunity()) : ?><dt class="col-5"><?= t('Community') ?></dt><dd class="col-7"><?= h($tournament->getCommunity()) ?></dd><?php endif; ?>
                        <dt class="col-5"><?= t('Embed') ?></dt><dd class="col-7"><a href="<?= h($data['embedUrl']) ?>" target="_blank" rel="noopener"><?= t('bracket module') ?></a></dd>
                        <dt class="col-5"><?= t('Last change') ?></dt><dd class="col-7"><?= $tournament->getLastChangedAt() ? h($tournament->getLastChangedAt()->format('d.m.Y H:i:s')) : '—' ?></dd>
                    </dl>
                </div>
            </div>

            <div class="card border-danger">
                <div class="card-body">
                    <p class="small text-muted"><?= t('Unlinking removes the tournament from the site (participants, matches and reports here). Nothing changes on Challonge.') ?></p>
                    <?php $form($url('unlink/' . $tid), '<i class="fas fa-unlink"></i> ' . t('Unlink tournament'), 'btn btn-outline-danger', [], t('Unlink "%s" from the site?', $tournament->getName())); ?>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($mode === 'settings') : ?>
    <?php
    /** @var \ChallongeTournaments\Config\Settings $settings */
    /** @var array<int, string> $groups */
    /** @var \ChallongeTournaments\Api\ClientInterface $client */
    /** @var string[] $fakeSlugs */
    ?>
    <div class="ccm-dashboard-header-buttons">
        <a href="<?= h($url()) ?>" class="btn btn-secondary"><?= t('Back') ?></a>
    </div>
    <form method="post" action="<?= h($url('save_settings')) ?>">
        <?php $token->output($tokenAction) ?>
        <div class="row g-4">
            <div class="col-lg-6">
                <h4><?= t('Challonge API') ?></h4>
                <div class="mb-3">
                    <label class="form-label" for="ct-api-key"><?= t('API key (v1)') ?></label>
                    <input type="password" class="form-control" id="ct-api-key" name="api_key" autocomplete="off" placeholder="<?= $settings->getApiKey() !== '' ? h($settings->getMaskedApiKey()) : t('not set') ?>">
                    <div class="form-text"><?= t('From %s (Developer Portal). Leave empty to keep the stored key. Free apps are limited to 500 requests per month: a sync is 3 requests, a report 2.', '<a href="https://connect.challonge.com" target="_blank" rel="noopener">connect.challonge.com</a>') ?></div>
                    <?php if ($settings->getApiKey() !== '') : ?>
                        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_api_key" value="1" id="ct-clear-key"><label class="form-check-label" for="ct-clear-key"><?= t('Remove the stored key') ?></label></div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="ct-community"><?= t('Default community (subdomain)') ?></label>
                    <input type="text" class="form-control" id="ct-community" name="community" value="<?= h($settings->getCommunity()) ?>" placeholder="turicane">
                </div>
                <div class="form-check mb-3">
                    <input type="hidden" name="fake_client" value="0">
                    <input class="form-check-input" type="checkbox" name="fake_client" value="1" id="ct-fake" <?= $settings->get('fake_client') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ct-fake"><?= t('Use the fake Challonge even with a key (development)') ?></label>
                </div>
                <?php if (!$client->isLive()) : ?>
                    <div class="alert alert-warning small">
                        <?= t('The fake Challonge is active. Its tournaments: %s.', implode(', ', array_map(static function ($s) {
                            return '<code>' . h($s) . '</code>';
                        }, $fakeSlugs))) ?>
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="reset_fake" value="1" id="ct-reset-fake"><label class="form-check-label" for="ct-reset-fake"><?= t('Reset the fake Challonge to its fixtures on save') ?></label></div>
                    </div>
                <?php endif; ?>

                <h4 class="mt-4"><?= t('Sync') ?></h4>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="ct-interval"><?= t('Sync interval (minutes)') ?></label>
                        <input type="number" min="1" class="form-control" id="ct-interval" name="sync_interval_minutes" value="<?= (int) $settings->getSyncIntervalMinutes() ?>">
                        <div class="form-text"><?= t('The task "Sync Challonge tournaments" skips tournaments synced more recently. Schedule the task under System › Automation.') ?></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="ct-stale"><?= t('Sync on view after (minutes, 0 = never)') ?></label>
                        <input type="number" min="0" class="form-control" id="ct-stale" name="stale_after_minutes" value="<?= (int) $settings->getStaleAfterMinutes() ?>">
                        <div class="form-text"><?= t('A running tournament opened in the dashboard or on a page is synced if its data is older than this.') ?></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <h4><?= t('Bracket embed') ?></h4>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="ct-theme"><?= t('Challonge theme ID') ?></label>
                        <input type="text" class="form-control" id="ct-theme" name="embed_theme" value="<?= h($settings->getEmbedTheme()) ?>" placeholder="<?= t('default') ?>">
                        <div class="form-text"><?= t('The numeric theme of the bracket module (Challonge › tournament › Settings › Bracket themes).') ?></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="ct-multiplier"><?= t('Size multiplier') ?></label>
                        <input type="text" class="form-control" id="ct-multiplier" name="embed_multiplier" value="<?= h($settings->getEmbedMultiplier()) ?>">
                    </div>
                </div>

                <h4 class="mt-4"><?= t('Result reports') ?></h4>
                <div class="mb-3">
                    <label class="form-label" for="ct-modgroup"><?= t('Global moderator group') ?></label>
                    <select class="form-select" id="ct-modgroup" name="moderator_group_id">
                        <?php foreach ($groups as $id => $label) : ?>
                            <option value="<?= (int) $id ?>" <?= (int) $id === $settings->getModeratorGroupID() ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text"><?= t('Its members moderate every tournament. Each tournament may add its own group.') ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="ct-autoconfirm"><?= t('Auto-confirm unopposed reports after (minutes, 0 = off)') ?></label>
                    <input type="number" min="0" class="form-control" id="ct-autoconfirm" name="auto_confirm_minutes" value="<?= (int) $settings->getAutoConfirmMinutes() ?>">
                    <div class="form-text"><?= t('A captain\'s report nobody confirmed or rejected within this time is written to Challonge by the sync task.') ?></div>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary mt-3"><?= t('Save settings') ?></button>
    </form>
<?php endif; ?>
