<?php

namespace ChallongeTournaments\Tournament;

use ChallongeTournaments\Config\Settings;
use ChallongeTournaments\Entity\Participant;
use ChallongeTournaments\Entity\Tournament;
use ChallongeTournaments\Entity\TournamentMatch;
use Concrete\Core\Application\Application;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\Group\Group;
use Concrete\Core\User\User;

/**
 * Who may do what: administrators and moderators (global group, per-tournament group) manage and confirm; captains
 * of a participant's team (or the single player) report their own matches. The Team Manager is a soft dependency.
 */
class Access
{
    /** @var Application */
    protected $app;

    /** @var Settings */
    protected $settings;

    /** @var array<int, int[]> uID => team IDs */
    protected $captainTeams = [];

    /** @var array<int, int[]> uID => team IDs */
    protected $memberTeams = [];

    public function __construct(Application $app, Settings $settings)
    {
        $this->app = $app;
        $this->settings = $settings;
    }

    public function isAdmin(?User $user): bool
    {
        if ($user === null || !$user->isRegistered()) {
            return false;
        }
        if ($user->isSuperUser()) {
            return true;
        }
        $dashboard = \Concrete\Core\Page\Page::getByPath('/dashboard/tournaments');

        return $dashboard && !$dashboard->isError() && (new Checker($dashboard))->canViewPage();
    }

    /**
     * Administrators, members of the global moderator group and of the tournament's moderator group.
     */
    public function isModerator(?User $user, ?Tournament $tournament = null): bool
    {
        if ($user === null || !$user->isRegistered()) {
            return false;
        }
        if ($this->isAdmin($user)) {
            return true;
        }
        $groupIDs = array_filter([
            $this->settings->getModeratorGroupID(),
            $tournament ? $tournament->getModeratorGroupID() : 0,
        ]);
        foreach ($groupIDs as $gID) {
            $group = Group::getByID((int) $gID);
            if ($group && !$group->isError() && $user->inGroup($group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user plays for the participant (team member or the single player).
     */
    public function isMemberOf(?User $user, Participant $participant): bool
    {
        if ($user === null || !$user->isRegistered()) {
            return false;
        }
        $uID = (int) $user->getUserID();
        if ($participant->getUserID() === $uID) {
            return true;
        }

        return $participant->getTeamID() !== null && in_array($participant->getTeamID(), $this->getMemberTeamIDs($uID), true);
    }

    /**
     * Whether the user may report for the participant: the single player themself or a captain of the team.
     */
    public function isCaptainOf(?User $user, Participant $participant): bool
    {
        if ($user === null || !$user->isRegistered()) {
            return false;
        }
        $uID = (int) $user->getUserID();
        if ($participant->getUserID() === $uID) {
            return true;
        }

        return $participant->getTeamID() !== null && in_array($participant->getTeamID(), $this->getCaptainTeamIDs($uID), true);
    }

    /**
     * The side (1 or 2) of the match the user may report for, null if none.
     */
    public function getReportableSide(?User $user, TournamentMatch $match): ?int
    {
        foreach ([1 => $match->getPlayer1(), 2 => $match->getPlayer2()] as $side => $participant) {
            if ($participant !== null && $this->isCaptainOf($user, $participant)) {
                return $side;
            }
        }

        return null;
    }

    public function mayReport(?User $user, TournamentMatch $match): bool
    {
        $tournament = $match->getTournament();
        if (!$match->isOpen() || !$tournament->isReportingEnabled()) {
            return $this->isModerator($user, $tournament) && $match->isOpen();
        }

        return $this->isModerator($user, $tournament) || $this->getReportableSide($user, $match) !== null;
    }

    /**
     * Whether the user may confirm a pending report: a moderator, or a captain of the other side.
     */
    public function mayConfirm(?User $user, TournamentMatch $match, ?int $reporterSide): bool
    {
        if ($this->isModerator($user, $match->getTournament())) {
            return true;
        }
        $side = $this->getReportableSide($user, $match);

        return $side !== null && $reporterSide !== null && $side !== $reporterSide;
    }

    /**
     * @return int[]
     */
    public function getMemberTeamIDs(int $uID): array
    {
        if (!isset($this->memberTeams[$uID])) {
            $this->memberTeams[$uID] = [];
            $this->captainTeams[$uID] = [];
            if (class_exists(\TeamManager\Team\TeamRepository::class)) {
                $teams = $this->app->make(\TeamManager\Team\TeamRepository::class);
                foreach ($teams->getForUser($uID) as $team) {
                    $this->memberTeams[$uID][] = $team->getID();
                    if ($team->isCaptain($uID)) {
                        $this->captainTeams[$uID][] = $team->getID();
                    }
                }
            }
        }

        return $this->memberTeams[$uID];
    }

    /**
     * @return int[]
     */
    public function getCaptainTeamIDs(int $uID): array
    {
        $this->getMemberTeamIDs($uID);

        return $this->captainTeams[$uID] ?? [];
    }
}
