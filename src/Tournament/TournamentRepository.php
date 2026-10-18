<?php

namespace ChallongeTournaments\Tournament;

use ChallongeTournaments\Entity\Participant;
use ChallongeTournaments\Entity\ScoreReport;
use ChallongeTournaments\Entity\Tournament;
use ChallongeTournaments\Entity\TournamentMatch;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads tournaments, matches and score reports. Writes go through TournamentService, Seeder, TournamentSync and
 * ScoreService.
 */
class TournamentRepository
{
    /** @var EntityManagerInterface */
    protected $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function getByID(int $id): ?Tournament
    {
        return $id > 0 ? $this->em->find(Tournament::class, $id) : null;
    }

    public function getByChallongeID(string $challongeID): ?Tournament
    {
        return $this->em->getRepository(Tournament::class)->findOneBy(['challongeID' => $challongeID]);
    }

    public function getBySlug(string $slug, ?string $community = null): ?Tournament
    {
        return $this->em->getRepository(Tournament::class)->findOneBy(['slug' => $slug, 'community' => $community]);
    }

    /**
     * @return Tournament[] ordered by event position, newest first across events
     */
    public function getAll(bool $onlyVisible = false): array
    {
        $qb = $this->em->createQueryBuilder()->select('t')->from(Tournament::class, 't')
            ->orderBy('t.eventID', 'DESC')->addOrderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC');
        if ($onlyVisible) {
            $qb->andWhere('t.visible = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Tournament[]
     */
    public function getByEvent(int $eventID, bool $onlyVisible = true): array
    {
        $qb = $this->em->createQueryBuilder()->select('t')->from(Tournament::class, 't')
            ->andWhere('t.eventID = :event')->setParameter('event', $eventID)
            ->orderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC');
        if ($onlyVisible) {
            $qb->andWhere('t.visible = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Tournaments without an event, for the "all" lists and the dashboard.
     *
     * @return Tournament[]
     */
    public function getUnassigned(bool $onlyVisible = true): array
    {
        $qb = $this->em->createQueryBuilder()->select('t')->from(Tournament::class, 't')
            ->andWhere('t.eventID IS NULL')->orderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC');
        if ($onlyVisible) {
            $qb->andWhere('t.visible = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Tournaments a sync may still change: not complete, or complete but not synced since completion.
     *
     * @return Tournament[]
     */
    public function getActive(): array
    {
        return array_values(array_filter($this->getAll(), static function (Tournament $t) {
            return $t->isActive();
        }));
    }

    /**
     * Tournaments whose last sync is older than the interval (or that were never synced).
     *
     * @return Tournament[]
     */
    public function getDueForSync(int $intervalMinutes): array
    {
        $cutoff = new DateTime('-' . max(1, $intervalMinutes) . ' minutes');

        return array_values(array_filter($this->getActive(), static function (Tournament $t) use ($cutoff) {
            return $t->getLastSyncedAt() === null || $t->getLastSyncedAt() < $cutoff;
        }));
    }

    public function getMatch(int $id): ?TournamentMatch
    {
        return $id > 0 ? $this->em->find(TournamentMatch::class, $id) : null;
    }

    public function getReport(int $id): ?ScoreReport
    {
        return $id > 0 ? $this->em->find(ScoreReport::class, $id) : null;
    }

    /**
     * @return ScoreReport[]
     */
    public function getPendingReports(?Tournament $tournament = null): array
    {
        $qb = $this->em->createQueryBuilder()->select('r')->from(ScoreReport::class, 'r')
            ->andWhere('r.status = :status')->setParameter('status', ScoreReport::STATUS_PENDING)
            ->orderBy('r.createdAt', 'ASC');
        if ($tournament !== null) {
            $qb->andWhere('r.tournament = :tournament')->setParameter('tournament', $tournament);
        }

        return $qb->getQuery()->getResult();
    }

    public function getPendingReportOfMatch(TournamentMatch $match): ?ScoreReport
    {
        return $this->em->getRepository(ScoreReport::class)->findOneBy(
            ['match' => $match, 'status' => ScoreReport::STATUS_PENDING],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * @return ScoreReport[] newest first
     */
    public function getReportsOfMatch(TournamentMatch $match, int $limit = 10): array
    {
        return $this->em->getRepository(ScoreReport::class)->findBy(['match' => $match], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * @return ScoreReport[] newest first
     */
    public function getRecentReports(Tournament $tournament, int $limit = 20): array
    {
        return $this->em->getRepository(ScoreReport::class)->findBy(['tournament' => $tournament], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * The participants a user plays for (as a team member or single), across the given tournaments.
     *
     * @param Tournament[] $tournaments
     *
     * @return Participant[]
     */
    public function getParticipantsOfUser(int $uID, array $tournaments, callable $teamIDsOfUser): array
    {
        if ($uID <= 0 || $tournaments === []) {
            return [];
        }
        $teamIDs = array_map('intval', $teamIDsOfUser($uID));
        $result = [];
        foreach ($tournaments as $tournament) {
            foreach ($tournament->getParticipants() as $participant) {
                if ($participant->getUserID() === $uID || ($participant->getTeamID() !== null && in_array($participant->getTeamID(), $teamIDs, true))) {
                    $result[] = $participant;
                }
            }
        }

        return $result;
    }

    /**
     * Open and pending matches of a participant, play order first.
     *
     * @return TournamentMatch[]
     */
    public function getMatchesOfParticipant(Participant $participant, ?array $states = null): array
    {
        $matches = [];
        foreach ($participant->getTournament()->getMatches() as $match) {
            if ($states !== null && !in_array($match->getState(), $states, true)) {
                continue;
            }
            if ($match->getSideOf($participant) !== null) {
                $matches[] = $match;
            }
        }
        usort($matches, [self::class, 'comparePlayOrder']);

        return $matches;
    }

    public static function comparePlayOrder(TournamentMatch $a, TournamentMatch $b): int
    {
        $orderA = $a->getSuggestedPlayOrder() ?? PHP_INT_MAX;
        $orderB = $b->getSuggestedPlayOrder() ?? PHP_INT_MAX;
        if ($orderA !== $orderB) {
            return $orderA <=> $orderB;
        }
        if ($a->getRound() !== $b->getRound()) {
            return abs($a->getRound()) <=> abs($b->getRound());
        }

        return strcmp((string) $a->getIdentifier(), (string) $b->getIdentifier());
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }
}
