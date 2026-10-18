<?php

namespace ChallongeTournaments\Score;

use ChallongeTournaments\Api\ApiException;
use ChallongeTournaments\Api\ClientInterface;
use ChallongeTournaments\Config\Settings;
use ChallongeTournaments\Entity\ScoreReport;
use ChallongeTournaments\Sync\TournamentSync;
use ChallongeTournaments\Tournament\TournamentRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes pending reports nobody opposed within auto_confirm_minutes to Challonge (off by default). Run by the sync
 * task.
 */
class AutoConfirm
{
    /** @var Settings */
    protected $settings;

    /** @var TournamentRepository */
    protected $repository;

    /** @var ClientInterface */
    protected $client;

    /** @var TournamentSync */
    protected $sync;

    /** @var EntityManagerInterface */
    protected $em;

    public function __construct(Settings $settings, TournamentRepository $repository, ClientInterface $client, TournamentSync $sync, EntityManagerInterface $em)
    {
        $this->settings = $settings;
        $this->repository = $repository;
        $this->client = $client;
        $this->sync = $sync;
        $this->em = $em;
    }

    /**
     * @return int reports applied
     */
    public function run(): int
    {
        $minutes = $this->settings->getAutoConfirmMinutes();
        if ($minutes <= 0) {
            return 0;
        }
        $cutoff = new DateTime('-' . $minutes . ' minutes');
        $applied = 0;
        foreach ($this->repository->getPendingReports() as $report) {
            if ($report->getCreatedAt() > $cutoff || !$report->getMatch()->isOpen()) {
                continue;
            }
            $match = $report->getMatch();
            $tournament = $match->getTournament();
            try {
                $resource = $this->client->reportMatch($tournament->getChallongeID(), $match->getChallongeID(), $report->getWinnerChallongeID(), $report->getScoresCsv(), $tournament->getCommunity());
            } catch (ApiException $e) {
                $report->resolve(ScoreReport::STATUS_FAILED, null, $e->getMessage());
                continue;
            }
            $this->sync->applyMatch($match, $resource);
            $report->resolve(ScoreReport::STATUS_APPLIED, null);
            $tournament->setLastSyncedAt(null);
            $applied++;
        }
        $this->em->flush();

        return $applied;
    }
}
