<?php

namespace ChallongeTournaments\Command\Task;

use ChallongeTournaments\Config\Settings;
use ChallongeTournaments\Score\AutoConfirm;
use ChallongeTournaments\Sync\TournamentSync;
use ChallongeTournaments\Tournament\TournamentRepository;
use Concrete\Core\Command\Task\Output\OutputAwareInterface;
use Concrete\Core\Command\Task\Output\OutputAwareTrait;

class SyncTournamentsCommandHandler implements OutputAwareInterface
{
    use OutputAwareTrait;

    /** @var TournamentRepository */
    protected $repository;

    /** @var TournamentSync */
    protected $sync;

    /** @var Settings */
    protected $settings;

    /** @var AutoConfirm */
    protected $autoConfirm;

    public function __construct(TournamentRepository $repository, TournamentSync $sync, Settings $settings, AutoConfirm $autoConfirm)
    {
        $this->repository = $repository;
        $this->sync = $sync;
        $this->settings = $settings;
        $this->autoConfirm = $autoConfirm;
    }

    public function __invoke(SyncTournamentsCommand $command): void
    {
        $applied = $this->autoConfirm->run();
        if ($applied > 0) {
            $this->output->write(t2('%d unopposed report written to Challonge.', '%d unopposed reports written to Challonge.', $applied));
        }
        $due = $this->repository->getDueForSync($this->settings->getSyncIntervalMinutes());
        if ($due === []) {
            $this->output->write(t('No tournament is due for a sync.'));

            return;
        }
        foreach ($due as $tournament) {
            $result = $this->sync->sync($tournament, false);
            $this->output->write(sprintf('%s: %s', $tournament->getName(), $result->summary()));
        }
    }
}
