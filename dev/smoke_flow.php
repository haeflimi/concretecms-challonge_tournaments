<?php
// Dev only: drives the result flow through the real services against the fake Challonge, as the dev users would on
// the site: captain reports → opponent captain confirms → written to Challonge → sync shows the next match. Then a
// moderator (uID 1) reports a match directly and reopens it. Needs seed_dev.php. Changes the fake state (run
// reset_fake.php + seed_dev.php to start over).
// Run: docker exec -u www-data devturicanech-app-1 php /app/concrete/bin/concrete c5:exec --no-interaction /app/packages/challonge_tournaments/dev/smoke_flow.php

use ChallongeTournaments\Entity\ScoreReport;
use ChallongeTournaments\Score\ScoreService;
use ChallongeTournaments\Sync\TournamentSync;
use ChallongeTournaments\Tournament\Access;
use ChallongeTournaments\Tournament\Presenter;
use ChallongeTournaments\Tournament\TournamentRepository;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;

$app = Concrete\Core\Support\Facade\Application::getFacadeApplication();
$out = static function (string $line) {
    echo $line, PHP_EOL;
};
$fail = 0;
$check = static function (bool $ok, string $what) use (&$fail, $out) {
    $out(($ok ? 'OK   ' : 'FAIL ') . $what);
    if (!$ok) {
        $fail++;
    }
};

$repository = $app->make(TournamentRepository::class);
$scores = $app->make(ScoreService::class);
$access = $app->make(Access::class);
$sync = $app->make(TournamentSync::class);
$users = $app->make(UserInfoRepository::class);
$userOf = static function (string $name) use ($users): User {
    $ui = $users->getByName($name);
    if ($ui === null) {
        throw new RuntimeException("user $name missing, run seed_dev.php");
    }

    return User::getByUserID($ui->getUserID());
};
$admin = User::getByUserID(1);

$tournament = $repository->getBySlug('lan35-rocket-league');
if ($tournament === null) {
    $out('Rocket League tournament not linked; run seed_dev.php');

    return;
}
$sync->sync($tournament, true);
$open = array_values(array_filter($tournament->getMatches()->toArray(), static function ($m) {
    return $m->isOpen() && $m->getPlayer1() && $m->getPlayer2();
}));
$check($open !== [], 'an open match with both sides exists (' . count($open) . ')');
$match = $open[0];
$p1 = $match->getPlayer1();
$p2 = $match->getPlayer2();
$out("Match {$match->getIdentifier()}: {$p1->getName()} (team {$p1->getTeamID()}) vs {$p2->getName()} (team {$p2->getTeamID()})");

// who is captain of which side
$teams = $app->make(\TeamManager\Team\TeamRepository::class);
$captainOf = static function ($participant) use ($teams, $userOf, $users): ?User {
    $team = $participant->getTeamID() ? $teams->getByID($participant->getTeamID()) : null;
    if ($team === null) {
        return null;
    }
    foreach ($team->getCaptains() as $member) {
        return User::getByUserID($member->getUserID());
    }

    return null;
};
$cap1 = $captainOf($p1);
$cap2 = $captainOf($p2);
$check($cap1 !== null && $cap2 !== null, 'both sides have a captain (' . ($cap1 ? $cap1->getUserName() : '-') . ', ' . ($cap2 ? $cap2->getUserName() : '-') . ')');
$check($access->getReportableSide($cap1, $match) === 1, 'captain 1 reports for side 1');
$check($access->getReportableSide($cap2, $match) === 2, 'captain 2 reports for side 2');
$mate = $userOf('tourney02');
$check($access->getReportableSide($mate, $match) === null || $mate->getUserID() === $cap1->getUserID(), 'a plain member may not report');
$check(!$access->isModerator($cap1, $tournament), 'captain is not a moderator');
$check($access->isModerator($admin, $tournament), 'admin is a moderator');

// wrong scores for the winner
try {
    $scores->report($match, $cap1, 1, '1-3,0-3');
    $check(false, 'scores contradicting the winner are rejected');
} catch (UserMessageException $e) {
    $check(true, 'scores contradicting the winner are rejected: ' . $e->getMessage());
}

// captain 1 reports, pending
$report = $scores->report($match, $cap1, 1, '3-1,2-3,3-0', 'gg');
$check($report->isPending(), 'captain report is pending');
$check($repository->getPendingReportOfMatch($match) !== null, 'pending report is found for the match');
$data = $app->make(Presenter::class)->tournamentData($tournament, $cap2);
$m = null;
foreach ($data['matches'] as $row) {
    if ($row['id'] === $match->getID()) {
        $m = $row;
    }
}
$check($m !== null && $m['pending'] !== null && $m['pending']['canConfirm'] === true, 'opponent captain sees the pending report and may confirm');
$check($m !== null && $m['mySide'] === 2, 'presenter marks the opponent side');

// captain 1 cannot confirm own report
$check(!$access->mayConfirm($cap1, $match, $report->getReporterSide()), 'reporter may not confirm own report');

// opponent confirms → applied
$scores->confirm($report, $cap2);
$check($report->getStatus() === ScoreReport::STATUS_APPLIED, 'report applied after confirmation');
$check($match->isComplete() && $match->getWinnerChallongeID() === $p1->getChallongeID(), 'match complete with the right winner (' . $match->getScoresCsv() . ')');
$check($tournament->getLastSyncedAt() === null, 'tournament marked for the next sync');
$result = $sync->sync($tournament, true);
$out('sync: ' . $result->summary());

// moderator reports the next open match directly and reopens it
$open = array_values(array_filter($tournament->getMatches()->toArray(), static function ($mm) {
    return $mm->isOpen() && $mm->getPlayer1() && $mm->getPlayer2();
}));
if ($open !== []) {
    $next = $open[0];
    $r2 = $scores->report($next, $admin, 2, '0-3,1-3');
    $check($r2->getStatus() === ScoreReport::STATUS_APPLIED && $next->isComplete(), "moderator report on {$next->getIdentifier()} applied at once");
    $scores->reopen($next, $admin);
    $check($next->isOpen(), 'moderator reopened the match');
    $sync->sync($tournament, true);
} else {
    $out('no further open match to test the moderator flow');
}

// state endpoint data for a guest
$guest = $app->make(Presenter::class)->tournamentData($tournament, null);
$check($guest['permissions']['signedIn'] === false && $guest['standings'] !== [], 'guest presenter data has standings and no permissions');

$out($fail === 0 ? 'ALL OK' : "$fail FAILED");
