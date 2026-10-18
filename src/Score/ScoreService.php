<?php

namespace ChallongeTournaments\Score;

use ChallongeTournaments\Api\ApiException;
use ChallongeTournaments\Api\ClientInterface;
use ChallongeTournaments\Api\MatchState;
use ChallongeTournaments\Entity\ScoreReport;
use ChallongeTournaments\Entity\TournamentMatch;
use ChallongeTournaments\Sync\TournamentSync;
use ChallongeTournaments\Tournament\Access;
use ChallongeTournaments\Tournament\TournamentRepository;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Result reporting on the site. A moderator's report is written to Challonge at once. A captain's report is pending
 * until the opposing captain confirms (or reports the same result) or a moderator confirms; a differing report of
 * the other side supersedes it and moderators decide. Applied results update the local match from Challonge's answer
 * and mark the tournament for the next sync (the following matches change on Challonge).
 */
class ScoreService
{
    const EVENT_REPORTED = 'on_challonge_result_reported';
    const EVENT_APPLIED = 'on_challonge_result_applied';

    /** @var EntityManagerInterface */
    protected $em;

    /** @var ClientInterface */
    protected $client;

    /** @var TournamentSync */
    protected $sync;

    /** @var Access */
    protected $access;

    /** @var TournamentRepository */
    protected $repository;

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    public function __construct(EntityManagerInterface $em, ClientInterface $client, TournamentSync $sync, Access $access, TournamentRepository $repository, EventDispatcherInterface $dispatcher)
    {
        $this->em = $em;
        $this->client = $client;
        $this->sync = $sync;
        $this->access = $access;
        $this->repository = $repository;
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param int $winnerSide 1 or 2
     * @param string $scoresCsv "16-9" or "3-1,2-3,3-2" (side 1 first)
     *
     * @return ScoreReport the report: applied (moderator, or the other side agreed) or pending
     *
     * @throws UserMessageException
     */
    public function report(TournamentMatch $match, User $user, int $winnerSide, string $scoresCsv, ?string $note = null): ScoreReport
    {
        $tournament = $match->getTournament();
        if (!$match->isOpen()) {
            throw new UserMessageException(t('This match is not open.'));
        }
        $player1 = $match->getPlayer1();
        $player2 = $match->getPlayer2();
        if ($player1 === null || $player2 === null) {
            throw new UserMessageException(t('Both sides of the match must be decided first.'));
        }
        $isModerator = $this->access->isModerator($user, $tournament);
        $side = $this->access->getReportableSide($user, $match);
        if (!$isModerator && ($side === null || !$tournament->isReportingEnabled())) {
            throw new UserMessageException(t('You may not report this match.'));
        }
        if (!in_array($winnerSide, [1, 2], true)) {
            throw new UserMessageException(t('Choose the winner.'));
        }
        $sets = TournamentMatch::parseScores($scoresCsv);
        if ($sets === []) {
            throw new UserMessageException(t('Enter the scores as "16-9" or, with several maps, "16-9,10-16,16-12".'));
        }
        $csv = implode(',', array_map(static function (array $set) {
            return $set[0] . '-' . $set[1];
        }, $sets));
        $wins = [0, 0];
        foreach ($sets as [$a, $b]) {
            if ($a > $b) {
                $wins[0]++;
            } elseif ($b > $a) {
                $wins[1]++;
            }
        }
        if ($wins[$winnerSide - 1] < $wins[2 - $winnerSide]) {
            throw new UserMessageException(t('The scores say the other side won. Check the winner and the order of the scores (%s first).', $player1->getName()));
        }
        $winnerID = $winnerSide === 1 ? (string) $match->getPlayer1ChallongeID() : (string) $match->getPlayer2ChallongeID();

        $report = new ScoreReport($match, (int) $user->getUserID());
        $report->setReporterSide($isModerator && $side === null ? null : $side);
        $report->setWinnerChallongeID($winnerID);
        $report->setScoresCsv($csv);
        $report->setNote($note);
        $this->em->persist($report);

        $pending = $this->repository->getPendingReportOfMatch($match);
        if ($isModerator) {
            if ($pending !== null) {
                $pending->resolve(ScoreReport::STATUS_SUPERSEDED, (int) $user->getUserID());
            }
            $this->em->flush();
            $this->apply($report, (int) $user->getUserID());

            return $report;
        }
        if ($pending !== null) {
            if ($pending->getReporterSide() === $side) {
                // the same side corrects its own report
                $pending->resolve(ScoreReport::STATUS_SUPERSEDED, (int) $user->getUserID());
            } elseif ($pending->getWinnerChallongeID() === $winnerID && $pending->getScoresCsv() === $csv) {
                // both sides agree: the first report is applied, this one records the agreement
                $report->resolve(ScoreReport::STATUS_SUPERSEDED, (int) $user->getUserID());
                $this->em->flush();
                $this->apply($pending, (int) $user->getUserID());

                return $pending;
            } else {
                // the sides disagree: the newer report waits too, moderators decide
                $pending->resolve(ScoreReport::STATUS_SUPERSEDED, (int) $user->getUserID());
                $report->setNote(trim(($report->getNote() ?? '') . ' ' . t('(disputes %s, reported by the other side)', $pending->getScoresCsv())));
            }
        }
        $this->em->flush();
        $this->dispatcher->dispatch(new GenericEvent($report), self::EVENT_REPORTED);

        return $report;
    }

    /**
     * @throws UserMessageException
     */
    public function confirm(ScoreReport $report, User $user): void
    {
        if (!$report->isPending()) {
            throw new UserMessageException(t('This report was already handled.'));
        }
        if (!$this->access->mayConfirm($user, $report->getMatch(), $report->getReporterSide())) {
            throw new UserMessageException(t('You may not confirm this report.'));
        }
        $this->apply($report, (int) $user->getUserID());
    }

    /**
     * @throws UserMessageException
     */
    public function reject(ScoreReport $report, User $user, ?string $note = null): void
    {
        if (!$report->isPending()) {
            throw new UserMessageException(t('This report was already handled.'));
        }
        if (!$this->access->mayConfirm($user, $report->getMatch(), $report->getReporterSide())) {
            throw new UserMessageException(t('You may not reject this report.'));
        }
        $report->resolve(ScoreReport::STATUS_REJECTED, (int) $user->getUserID(), $note !== null && trim($note) !== '' ? trim($note) : null);
        $this->em->flush();
    }

    /**
     * @throws UserMessageException
     */
    public function withdraw(ScoreReport $report, User $user): void
    {
        if (!$report->isPending()) {
            throw new UserMessageException(t('This report was already handled.'));
        }
        if ($report->getReportedBy() !== (int) $user->getUserID() && !$this->access->isModerator($user, $report->getTournament())) {
            throw new UserMessageException(t('Only the reporter may withdraw a report.'));
        }
        $report->resolve(ScoreReport::STATUS_WITHDRAWN, (int) $user->getUserID());
        $this->em->flush();
    }

    /**
     * Marks a match as underway (or not) on Challonge: captains of either side and moderators.
     *
     * @throws UserMessageException
     */
    public function setUnderway(TournamentMatch $match, User $user, bool $underway): void
    {
        $tournament = $match->getTournament();
        if (!$match->isOpen()) {
            throw new UserMessageException(t('This match is not open.'));
        }
        if (!$this->access->isModerator($user, $tournament) && $this->access->getReportableSide($user, $match) === null) {
            throw new UserMessageException(t('You may not change this match.'));
        }
        try {
            $resource = $this->client->changeMatchState($tournament->getChallongeID(), $match->getChallongeID(), $underway ? MatchState::MARK_AS_UNDERWAY : MatchState::UNMARK_AS_UNDERWAY, $tournament->getCommunity());
        } catch (ApiException $e) {
            throw new UserMessageException($e->getMessage());
        }
        $this->sync->applyMatch($match, $resource);
        if ($match->getUnderwayAt() === null && $underway) {
            // the schema does not expose the flag: remember it locally until the next sync says otherwise
            $match->setUnderwayAt(new \DateTime());
        }
        $this->em->flush();
    }

    /**
     * Reopens a complete match on Challonge (moderators).
     *
     * @throws UserMessageException
     */
    public function reopen(TournamentMatch $match, User $user): void
    {
        $tournament = $match->getTournament();
        if (!$this->access->isModerator($user, $tournament)) {
            throw new UserMessageException(t('Only moderators may reopen a match.'));
        }
        try {
            $resource = $this->client->changeMatchState($tournament->getChallongeID(), $match->getChallongeID(), MatchState::REOPEN, $tournament->getCommunity());
        } catch (ApiException $e) {
            throw new UserMessageException($e->getMessage());
        }
        $this->sync->applyMatch($match, $resource);
        $tournament->setLastSyncedAt(null);
        $this->em->flush();
    }

    /**
     * Writes the report to Challonge.
     *
     * @throws UserMessageException
     */
    protected function apply(ScoreReport $report, int $uID): void
    {
        $match = $report->getMatch();
        $tournament = $match->getTournament();
        try {
            $resource = $this->client->reportMatch($tournament->getChallongeID(), $match->getChallongeID(), $report->getWinnerChallongeID(), $report->getScoresCsv(), $tournament->getCommunity());
        } catch (ApiException $e) {
            $report->resolve(ScoreReport::STATUS_FAILED, $uID, $e->getMessage());
            $this->em->flush();
            throw new UserMessageException(t('Challonge did not accept the result: %s', $e->getMessage()));
        }
        $this->sync->applyMatch($match, $resource);
        if ($match->getWinnerChallongeID() === null) {
            // the fake and real API both return the winner; be safe if the answer was thin
            $match->setWinnerChallongeID($report->getWinnerChallongeID());
            $match->setState(TournamentMatch::STATE_COMPLETE);
            $match->setScoresCsv($report->getScoresCsv());
            $match->setCompletedAt(new \DateTime());
        }
        $report->resolve(ScoreReport::STATUS_APPLIED, $uID);
        // the following matches changed on Challonge: due at the next sync run
        $tournament->setLastSyncedAt(null);
        $this->em->flush();
        $this->dispatcher->dispatch(new GenericEvent($report), self::EVENT_APPLIED);
    }
}
