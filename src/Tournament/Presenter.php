<?php

namespace ChallongeTournaments\Tournament;

use ChallongeTournaments\Config\Settings;
use ChallongeTournaments\Entity\Participant;
use ChallongeTournaments\Entity\ScoreReport;
use ChallongeTournaments\Entity\Tournament;
use ChallongeTournaments\Entity\TournamentMatch;
use Concrete\Core\Localization\Service\Date;
use Concrete\Core\User\User;

/**
 * Turns a tournament into the array the blocks, the Blackout templates and the AJAX endpoint share: participants,
 * matches (with what the current user may do), standings, the embed URL. Pure read; no Challonge request.
 */
class Presenter
{
    /** @var Access */
    protected $access;

    /** @var TournamentRepository */
    protected $repository;

    /** @var Settings */
    protected $settings;

    /** @var Date */
    protected $dates;

    public function __construct(Access $access, TournamentRepository $repository, Settings $settings, Date $dates)
    {
        $this->access = $access;
        $this->repository = $repository;
        $this->settings = $settings;
        $this->dates = $dates;
    }

    public function tournamentData(Tournament $tournament, ?User $user): array
    {
        $isModerator = $this->access->isModerator($user, $tournament);
        $uID = $user !== null && $user->isRegistered() ? (int) $user->getUserID() : 0;
        $myTeamIDs = $uID ? $this->access->getMemberTeamIDs($uID) : [];

        $participants = [];
        foreach ($tournament->getParticipants() as $participant) {
            $participants[$participant->getChallongeID()] = $this->participantData($participant, $uID, $myTeamIDs);
        }
        uasort($participants, static function ($a, $b) {
            return [$a['seed'], $a['name']] <=> [$b['seed'], $b['name']];
        });

        $pending = [];
        foreach ($this->repository->getPendingReports($tournament) as $report) {
            $pending[$report->getMatch()->getID()] = $report;
        }
        $matches = [];
        $maxRound = 0;
        foreach ($tournament->getMatches() as $match) {
            $maxRound = max($maxRound, $match->getRound());
        }
        $all = $tournament->getMatches()->toArray();
        usort($all, [TournamentRepository::class, 'comparePlayOrder']);
        foreach ($all as $match) {
            $matches[] = $this->matchData($match, $tournament, $user, $isModerator, $maxRound, $pending[$match->getID()] ?? null, $uID);
        }
        $sections = ['live' => [], 'upcoming' => [], 'completed' => []];
        foreach ($matches as $m) {
            if ($m['state'] === TournamentMatch::STATE_COMPLETE) {
                $sections['completed'][] = $m['id'];
            } elseif ($m['state'] === TournamentMatch::STATE_OPEN) {
                $sections[$m['underway'] ? 'live' : 'upcoming'][] = $m['id'];
            } elseif ($m['p1'] !== null || $m['p2'] !== null) {
                $sections['upcoming'][] = $m['id'];
            }
        }
        // completed newest first
        $sections['completed'] = array_reverse($sections['completed']);

        return [
            'id' => $tournament->getID(),
            'name' => $tournament->getName(),
            'game' => $tournament->getGameName(),
            'type' => $tournament->getTournamentType(),
            'typeLabel' => self::typeLabel($tournament->getTournamentType()),
            'state' => $tournament->getState(),
            'stateLabel' => self::stateLabel($tournament->getState()),
            'phase' => $tournament->isComplete() ? 'complete' : ($tournament->isUnderway() ? 'underway' : 'pending'),
            'description' => $tournament->getDescription(),
            'url' => $tournament->getChallongeURL(),
            'embedUrl' => $this->embedURL($tournament),
            'startsAt' => $tournament->getStartsAt() ? $tournament->getStartsAt()->format(DATE_ATOM) : null,
            'startsAtLabel' => $tournament->getStartsAt() ? $this->dates->formatDateTime($tournament->getStartsAt(), false) : null,
            'participantsCount' => count($participants),
            'lastSyncedAt' => $tournament->getLastSyncedAt() ? $tournament->getLastSyncedAt()->format(DATE_ATOM) : null,
            'lastChangedAt' => $tournament->getLastChangedAt() ? $tournament->getLastChangedAt()->format(DATE_ATOM) : null,
            'participants' => array_values($participants),
            'matches' => $matches,
            'sections' => $sections,
            'standings' => $this->standings($tournament, $participants),
            'permissions' => [
                'isModerator' => $isModerator,
                'reportingEnabled' => $tournament->isReportingEnabled(),
                'signedIn' => $uID > 0,
            ],
            'pendingCount' => count($pending),
        ];
    }

    /**
     * The matches of the user's own participants across tournaments, for the "my matches" block.
     *
     * @param Tournament[] $tournaments
     *
     * @return array<int, array{tournament: array, participant: array, matches: array}>
     */
    public function myMatches(array $tournaments, User $user): array
    {
        $uID = (int) $user->getUserID();
        $teamIDs = $this->access->getMemberTeamIDs($uID);
        $result = [];
        foreach ($tournaments as $tournament) {
            $data = null;
            foreach ($tournament->getParticipants() as $participant) {
                $mine = $participant->getUserID() === $uID || ($participant->getTeamID() !== null && in_array($participant->getTeamID(), $teamIDs, true));
                if (!$mine) {
                    continue;
                }
                $data = $data ?? $this->tournamentData($tournament, $user);
                $matches = array_values(array_filter($data['matches'], static function ($m) use ($participant) {
                    return ($m['p1']['id'] ?? null) === $participant->getChallongeID() || ($m['p2']['id'] ?? null) === $participant->getChallongeID()
                        || in_array($m['p1']['id'] ?? '', $participant->getGroupPlayerIDs(), true) || in_array($m['p2']['id'] ?? '', $participant->getGroupPlayerIDs(), true);
                }));
                $result[] = [
                    'tournament' => array_diff_key($data, ['matches' => 1, 'standings' => 1, 'participants' => 1, 'sections' => 1]),
                    'participant' => $this->participantData($participant, $uID, $teamIDs),
                    'matches' => $matches,
                ];
            }
        }

        return $result;
    }

    public function embedURL(Tournament $tournament): string
    {
        $params = ['multiplier' => $this->settings->getEmbedMultiplier(), 'show_final_results' => 1, 'show_standings' => 1];
        if ($this->settings->getEmbedTheme() !== '') {
            $params['theme'] = $this->settings->getEmbedTheme();
        }

        return $tournament->getEmbedURL($params);
    }

    protected function participantData(Participant $participant, int $uID, array $myTeamIDs): array
    {
        return [
            'id' => $participant->getChallongeID(),
            'name' => $participant->getName(),
            'seed' => $participant->getSeed(),
            'finalRank' => $participant->getFinalRank(),
            'active' => $participant->isActive(),
            'teamID' => $participant->getTeamID(),
            'userID' => $participant->getUserID(),
            'mine' => $uID > 0 && ($participant->getUserID() === $uID || ($participant->getTeamID() !== null && in_array($participant->getTeamID(), $myTeamIDs, true))),
        ];
    }

    protected function matchData(TournamentMatch $match, Tournament $tournament, ?User $user, bool $isModerator, int $maxRound, ?ScoreReport $pending, int $uID): array
    {
        $p1 = $match->getPlayer1();
        $p2 = $match->getPlayer2();
        $side = $user ? $this->access->getReportableSide($user, $match) : null;
        $canReport = $match->isOpen() && $p1 !== null && $p2 !== null && ($isModerator || ($side !== null && $tournament->isReportingEnabled()));
        $pendingData = null;
        if ($pending !== null) {
            $winner = $pending->getWinner();
            $pendingData = [
                'id' => $pending->getID(),
                'scores' => $pending->getScoresCsv(),
                'sets' => TournamentMatch::parseScores($pending->getScoresCsv()),
                'winnerID' => $pending->getWinnerChallongeID(),
                'winnerName' => $winner ? $winner->getName() : '',
                'reporterSide' => $pending->getReporterSide(),
                'note' => $pending->getNote(),
                'createdAt' => $pending->getCreatedAt()->format(DATE_ATOM),
                'mine' => $pending->getReportedBy() === $uID,
                'canConfirm' => $user ? $this->access->mayConfirm($user, $match, $pending->getReporterSide()) : false,
                'canWithdraw' => $uID > 0 && ($pending->getReportedBy() === $uID || $isModerator),
            ];
        }
        $sets = TournamentMatch::parseScores($match->getScoresCsv());

        return [
            'id' => $match->getID(),
            'challongeID' => $match->getChallongeID(),
            'state' => $match->getState(),
            'round' => $match->getRound(),
            'roundLabel' => self::roundLabel($tournament, $match, $maxRound),
            'identifier' => $match->getIdentifier(),
            'order' => $match->getSuggestedPlayOrder(),
            'groupID' => $match->getGroupID(),
            'p1' => $p1 ? ['id' => $p1->getChallongeID(), 'name' => $p1->getName(), 'seed' => $p1->getSeed()] : null,
            'p2' => $p2 ? ['id' => $p2->getChallongeID(), 'name' => $p2->getName(), 'seed' => $p2->getSeed()] : null,
            'winnerID' => $match->getWinnerChallongeID(),
            'winnerSide' => $match->getWinnerChallongeID() === null ? null : ($p1 && $p1->matchesPlayerID($match->getWinnerChallongeID()) ? 1 : 2),
            'scores' => $match->getScoresCsv(),
            'sets' => $sets,
            'setWins' => $match->getSetWins(),
            'underway' => $match->isUnderway(),
            'underwayAt' => $match->getUnderwayAt() ? $match->getUnderwayAt()->format(DATE_ATOM) : null,
            'scheduledAt' => $match->getScheduledTime() ? $match->getScheduledTime()->format(DATE_ATOM) : null,
            'scheduledLabel' => $match->getScheduledTime() ? $this->dates->formatDateTime($match->getScheduledTime(), false) : null,
            'completedAt' => $match->getCompletedAt() ? $match->getCompletedAt()->format(DATE_ATOM) : null,
            'mySide' => $side,
            'canReport' => $canReport,
            'canToggleUnderway' => $match->isOpen() && ($isModerator || $side !== null),
            'canReopen' => $match->isComplete() && $isModerator,
            'pending' => $pendingData,
        ];
    }

    /**
     * @param array<string, array> $participants challonge ID => participant data
     */
    protected function standings(Tournament $tournament, array $participants): array
    {
        $rows = [];
        foreach ($participants as $id => $p) {
            $rows[$id] = ['participant' => $p, 'rank' => $p['finalRank'], 'wins' => 0, 'losses' => 0, 'setWins' => 0, 'setLosses' => 0, 'diff' => 0, 'played' => 0];
        }
        foreach ($tournament->getMatches() as $match) {
            if (!$match->isComplete()) {
                continue;
            }
            $p1 = $match->getPlayer1();
            $p2 = $match->getPlayer2();
            if ($p1 === null || $p2 === null) {
                continue;
            }
            $id1 = $p1->getChallongeID();
            $id2 = $p2->getChallongeID();
            if (!isset($rows[$id1], $rows[$id2])) {
                continue;
            }
            $rows[$id1]['played']++;
            $rows[$id2]['played']++;
            $winner = $match->getWinner();
            if ($winner !== null) {
                $w = $winner->getChallongeID();
                $l = $w === $id1 ? $id2 : $id1;
                if (isset($rows[$w], $rows[$l])) {
                    $rows[$w]['wins']++;
                    $rows[$l]['losses']++;
                }
            }
            foreach (TournamentMatch::parseScores($match->getScoresCsv()) as [$a, $b]) {
                $rows[$id1]['setWins'] += $a;
                $rows[$id1]['setLosses'] += $b;
                $rows[$id2]['setWins'] += $b;
                $rows[$id2]['setLosses'] += $a;
            }
        }
        foreach ($rows as &$row) {
            $row['diff'] = $row['setWins'] - $row['setLosses'];
        }
        unset($row);
        $hasFinalRanks = $tournament->isComplete() && count(array_filter($rows, static function ($r) {
            return $r['rank'] !== null;
        })) > 0;
        uasort($rows, static function ($a, $b) use ($hasFinalRanks) {
            if ($hasFinalRanks) {
                return [$a['rank'] ?? PHP_INT_MAX, $a['participant']['seed']] <=> [$b['rank'] ?? PHP_INT_MAX, $b['participant']['seed']];
            }

            return [$b['wins'], $a['losses'], $b['diff'], $a['participant']['seed']] <=> [$a['wins'], $b['losses'], $a['diff'], $b['participant']['seed']];
        });
        if (!$hasFinalRanks) {
            $rank = 0;
            $previous = null;
            $i = 0;
            foreach ($rows as &$row) {
                $i++;
                $key = [$row['wins'], $row['losses'], $row['diff']];
                if ($key !== $previous) {
                    $rank = $i;
                    $previous = $key;
                }
                $row['rank'] = $rank;
            }
            unset($row);
        }

        return array_values($rows);
    }

    public static function roundLabel(Tournament $tournament, TournamentMatch $match, int $maxRound): string
    {
        $round = $match->getRound();
        if ($match->isGroupStage()) {
            return t('Group %1$s, round %2$d', $match->getGroupID(), abs($round));
        }
        $type = $tournament->getTournamentType();
        if (in_array($type, ['single elimination', 'double elimination'], true) && $maxRound > 0) {
            if ($round < 0) {
                return t('Losers round %d', abs($round));
            }
            $fromEnd = $maxRound - $round;
            if ($type === 'double elimination') {
                // the last winners round is the grand final (and a possible reset)
                if ($fromEnd === 0) {
                    return t('Grand final');
                }
                $fromEnd--;
            }
            switch ($fromEnd) {
                case 0:
                    return t('Final');
                case 1:
                    return t('Semifinal');
                case 2:
                    return t('Quarterfinal');
                case 3:
                    return t('Round of 16');
            }
        }

        return t('Round %d', abs($round));
    }

    public static function stateLabel(string $state): string
    {
        switch ($state) {
            case Tournament::STATE_PENDING:
                return t('Not started');
            case Tournament::STATE_CHECKING_IN:
                return t('Check-in');
            case Tournament::STATE_CHECKED_IN:
                return t('Checked in');
            case 'accepting_predictions':
                return t('Predictions open');
            case Tournament::STATE_GROUP_STAGES_UNDERWAY:
                return t('Group stage');
            case Tournament::STATE_GROUP_STAGES_FINALIZED:
                return t('Group stage finished');
            case Tournament::STATE_UNDERWAY:
                return t('Running');
            case Tournament::STATE_AWAITING_REVIEW:
                return t('Awaiting review');
            case Tournament::STATE_COMPLETE:
                return t('Finished');
        }

        return ucfirst(str_replace('_', ' ', $state));
    }

    public static function typeLabel(string $type): string
    {
        switch ($type) {
            case 'single elimination':
                return t('Single elimination');
            case 'double elimination':
                return t('Double elimination');
            case 'round robin':
                return t('Round robin');
            case 'swiss':
                return t('Swiss');
            case 'free for all':
                return t('Free for all');
        }

        return ucfirst($type);
    }

    /**
     * Labels of the match states for the lists.
     */
    public static function matchStateLabel(array $match): string
    {
        if ($match['state'] === TournamentMatch::STATE_COMPLETE) {
            return t('Finished');
        }
        if ($match['state'] === TournamentMatch::STATE_OPEN) {
            return $match['underway'] ? t('Live') : t('Ready to play');
        }

        return t('Waiting');
    }
}
