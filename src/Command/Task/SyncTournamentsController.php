<?php

namespace ChallongeTournaments\Command\Task;

use Concrete\Core\Command\Task\Controller\AbstractController;
use Concrete\Core\Command\Task\Input\InputInterface;
use Concrete\Core\Command\Task\Runner\CommandTaskRunner;
use Concrete\Core\Command\Task\Runner\TaskRunnerInterface;
use Concrete\Core\Command\Task\TaskInterface;

/**
 * The task "challonge_sync": pulls every tournament that may still change and is due (sync_interval_minutes).
 * Schedule it in Dashboard › System › Automation every few minutes; it only talks to Challonge for due tournaments.
 */
class SyncTournamentsController extends AbstractController
{
    public function getName(): string
    {
        return t('Sync Challonge tournaments');
    }

    public function getDescription(): string
    {
        return t('Pulls the participants, matches and state of the running Challonge tournaments that are due for a sync.');
    }

    public function getConsoleCommandName(): string
    {
        return 'challonge-sync';
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        return new CommandTaskRunner($task, new SyncTournamentsCommand(), t('Challonge tournaments synced.'));
    }
}
