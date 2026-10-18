<?php

namespace ChallongeTournaments\Sync;

use ChallongeTournaments\Api\ApiException;
use ChallongeTournaments\Api\ClientInterface;
use ChallongeTournaments\Api\JsonApi;
use ChallongeTournaments\Entity\Participant;
use ChallongeTournaments\Entity\Tournament;
use ChallongeTournaments\Entity\TournamentMatch;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Pulls a tournament with its participants and matches from Challonge into the local tables (three requests per
 * sync). Detects changes through a hash over the relevant fields and dispatches `on_challonge_tournament_synced`
 * with the diff, which is where a live layer (Mercure) will hook in later (docs/live-later.md).
 */
class TournamentSync
{
    const EVENT = 'on_challonge_tournament_synced';

    /** @var EntityManagerInterface */
    protected $em;

    /** @var ClientInterface */
    protected $client;

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    public function __construct(EntityManagerInterface $em, ClientInterface $client, EventDispatcherInterface $dispatcher)
    {
        $this->em = $em;
        $this->client = $client;
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param bool $throw rethrow API errors (the dashboard wants them); otherwise they are stored on the tournament
     *
     * @return SyncResult
     *
     * @throws ApiException
     */
    public function sync(Tournament $tournament, bool $throw = true): SyncResult
    {
        $before = $this->snapshot($tournament);
        $id = $tournament->getChallongeID() !== '' ? $tournament->getChallongeID() : $tournament->getSlug();
        $community = $tournament->getCommunity();
        try {
            $resource = $this->client->getTournament($id, $community);
            $participants = $this->client->listParticipants($id, $community);
            $matches = $this->client->listMatches($id, $community);
        } catch (ApiException $e) {
            $tournament->setLastSyncError($e->getMessage());
            $this->em->flush();
            if ($throw) {
                throw $e;
            }

            return new SyncResult($tournament, false, [], $e->getMessage());
        }
        $this->applyTournament($tournament, $resource);
        $this->applyParticipants($tournament, $participants);
        $this->applyMatches($tournament, $matches);

        $after = $this->snapshot($tournament);
        $hash = sha1(json_encode($after));
        $changed = $hash !== $tournament->getSyncHash();
        $now = new DateTime();
        $tournament->setSyncHash($hash);
        $tournament->setLastSyncedAt($now);
        $tournament->setLastSyncError(null);
        $diff = [];
        if ($changed) {
            $tournament->setLastChangedAt($now);
            $diff = $this->diff($before, $after);
        }
        $this->em->flush();
        $result = new SyncResult($tournament, $changed, $diff, null);
        if ($changed) {
            $this->dispatcher->dispatch(new GenericEvent($tournament, ['result' => $result]), self::EVENT);
        }

        return $result;
    }

    /**
     * @param array{id: string, attributes: array} $resource
     */
    public function applyTournament(Tournament $tournament, array $resource): void
    {
        $a = $resource['attributes'];
        if (!empty($resource['id'])) {
            $tournament->setChallongeID((string) $resource['id']);
        }
        if (!empty($a['url'])) {
            $tournament->setSlug((string) $a['url']);
        }
        if (!empty($a['name'])) {
            $tournament->setName((string) $a['name']);
        }
        $tournament->setGameName(isset($a['game_name']) && $a['game_name'] !== '' ? (string) $a['game_name'] : null);
        if (!empty($a['tournament_type'])) {
            $tournament->setTournamentType(strtolower(str_replace('_', ' ', (string) $a['tournament_type'])));
        }
        if (!empty($a['state'])) {
            $tournament->setState((string) $a['state']);
        }
        $tournament->setStartsAt(JsonApi::date($a['starts_at'] ?? null));
        $tournament->setStartedAt(JsonApi::date($a['started_at'] ?? null));
        $completedAt = JsonApi::date($a['completed_at'] ?? null);
        if ($completedAt === null && $tournament->getState() === Tournament::STATE_COMPLETE) {
            // the schema has no completed_at: the moment we first saw it complete
            $completedAt = $tournament->getCompletedAt() ?? new DateTime();
        } elseif ($tournament->getState() !== Tournament::STATE_COMPLETE) {
            $completedAt = null;
        }
        $tournament->setCompletedAt($completedAt);
        $url = (string) ($a['full_challonge_url'] ?? '');
        if ($url === '' && $tournament->getSlug() !== '') {
            $host = $tournament->getCommunity() ? $tournament->getCommunity() . '.challonge.com' : 'challonge.com';
            $url = 'https://' . $host . '/' . $tournament->getSlug();
        }
        $tournament->setFullURL($url);
        $tournament->setLiveImageURL(isset($a['live_image_url']) && $a['live_image_url'] !== '' ? (string) $a['live_image_url'] : null);
        if (isset($a['participants_count'])) {
            $tournament->setParticipantsCount((int) $a['participants_count']);
        }
        $tournament->setGroupStagesEnabled(!empty($a['group_stage_enabled']) || !empty($a['group_stages_enabled']));
        $tournament->setDescription(isset($a['description']) && $a['description'] !== '' ? (string) $a['description'] : null);
        $tournament->setAttributes($a);
    }

    /**
     * @param array<int, array{id: string, attributes: array}> $resources
     */
    public function applyParticipants(Tournament $tournament, array $resources): void
    {
        $seen = [];
        foreach ($resources as $resource) {
            $id = (string) $resource['id'];
            if ($id === '') {
                continue;
            }
            $seen[$id] = true;
            $a = $resource['attributes'];
            $participant = $tournament->getParticipantByChallongeID($id);
            if ($participant === null) {
                $participant = new Participant($tournament, $id);
                $tournament->getParticipants()->add($participant);
                $this->em->persist($participant);
            }
            $participant->setName((string) ($a['name'] ?? $a['display_name'] ?? ''));
            $participant->setSeed((int) ($a['seed'] ?? 0));
            $participant->setFinalRank(isset($a['final_rank']) && $a['final_rank'] !== null ? (int) $a['final_rank'] : null);
            $active = $a['states']['active'] ?? $a['active'] ?? true;
            $participant->setActive((bool) $active);
            $participant->setMisc(isset($a['misc']) ? (string) $a['misc'] : null);
            $groupIDs = $a['group_player_ids'] ?? [];
            $participant->setGroupPlayerIDs(is_array($groupIDs) ? $groupIDs : []);
            $participant->setAttributes($a);
            $participant->touch();
        }
        foreach ($tournament->getParticipants()->toArray() as $participant) {
            if (!isset($seen[$participant->getChallongeID()])) {
                $tournament->getParticipants()->removeElement($participant);
                $this->em->remove($participant);
            }
        }
        $tournament->setParticipantsCount(count($seen));
    }

    /**
     * @param array<int, array{id: string, attributes: array}> $resources
     */
    public function applyMatches(Tournament $tournament, array $resources): void
    {
        $existing = [];
        foreach ($tournament->getMatches() as $match) {
            $existing[$match->getChallongeID()] = $match;
        }
        $seen = [];
        foreach ($resources as $resource) {
            $id = (string) $resource['id'];
            if ($id === '') {
                continue;
            }
            $seen[$id] = true;
            $match = $existing[$id] ?? null;
            if ($match === null) {
                $match = new TournamentMatch($tournament, $id);
                $tournament->getMatches()->add($match);
                $this->em->persist($match);
            }
            $this->applyMatch($match, $resource);
        }
        foreach ($existing as $id => $match) {
            if (!isset($seen[$id])) {
                $tournament->getMatches()->removeElement($match);
                $this->em->remove($match);
            }
        }
    }

    /**
     * @param array{id: string, attributes: array} $resource
     */
    public function applyMatch(TournamentMatch $match, array $resource): void
    {
        $a = $resource['attributes'];
        $player1 = JsonApi::playerID($a, 1);
        $player2 = JsonApi::playerID($a, 2);
        $match->setState((string) ($a['state'] ?? TournamentMatch::STATE_PENDING));
        $match->setRound((int) ($a['round'] ?? 0));
        $match->setIdentifier(isset($a['identifier']) ? (string) $a['identifier'] : null);
        $match->setSuggestedPlayOrder(isset($a['suggested_play_order']) && $a['suggested_play_order'] !== null ? (int) $a['suggested_play_order'] : null);
        $match->setGroupID(isset($a['group_id']) && $a['group_id'] !== null ? (string) $a['group_id'] : null);
        $match->setPlayer1ChallongeID($player1);
        $match->setPlayer2ChallongeID($player2);
        $winner = isset($a['winner_id']) && $a['winner_id'] !== null && $a['winner_id'] !== '' ? (string) $a['winner_id'] : null;
        $loser = isset($a['loser_id']) && $a['loser_id'] !== null && $a['loser_id'] !== '' ? (string) $a['loser_id'] : null;
        if ($loser === null && $winner !== null && $player1 !== null && $player2 !== null) {
            $loser = $winner === $player1 ? $player2 : ($winner === $player2 ? $player1 : null);
        }
        $match->setWinnerChallongeID($winner);
        $match->setLoserChallongeID($loser);
        $match->setScoresCsv(JsonApi::setsToCsv(JsonApi::sets($a, $player1, $player2)));
        $match->setUnderwayAt(JsonApi::date($a['underway_at'] ?? null));
        $match->setScheduledTime(JsonApi::date($a['scheduled_time'] ?? null));
        $completedAt = JsonApi::date($a['completed_at'] ?? null);
        if ($match->isComplete()) {
            $completedAt = $completedAt ?? $match->getCompletedAt() ?? JsonApi::date($a['timestamps']['updated_at'] ?? $a['updated_at'] ?? null) ?? new DateTime();
        } else {
            $completedAt = null;
        }
        $match->setCompletedAt($completedAt);
        $match->setAttributes($a);
        $match->touch();
    }

    /**
     * The fields whose change counts as a change of the tournament.
     */
    protected function snapshot(Tournament $tournament): array
    {
        $participants = [];
        foreach ($tournament->getParticipants() as $p) {
            $participants[$p->getChallongeID()] = [$p->getName(), $p->getSeed(), $p->getFinalRank(), $p->isActive(), $p->getTeamID(), $p->getUserID()];
        }
        ksort($participants);
        $matches = [];
        foreach ($tournament->getMatches() as $m) {
            $matches[$m->getChallongeID()] = [
                'state' => $m->getState(), 'p1' => $m->getPlayer1ChallongeID(), 'p2' => $m->getPlayer2ChallongeID(),
                'winner' => $m->getWinnerChallongeID(), 'scores' => $m->getScoresCsv(), 'underway' => $m->getUnderwayAt() !== null,
                'round' => $m->getRound(), 'identifier' => $m->getIdentifier(), 'order' => $m->getSuggestedPlayOrder(),
            ];
        }
        ksort($matches);

        return [
            'tournament' => [$tournament->getName(), $tournament->getState(), $tournament->getTournamentType(), $tournament->getStartsAt() ? $tournament->getStartsAt()->format('c') : null],
            'participants' => $participants,
            'matches' => $matches,
        ];
    }

    /**
     * @return array{state: array{0: string, 1: string}|null, matches: array<string, array{before: array|null, after: array|null}>, participants: bool}
     */
    protected function diff(array $before, array $after): array
    {
        $diff = ['state' => null, 'matches' => [], 'participants' => $before['participants'] !== $after['participants']];
        if (($before['tournament'][1] ?? null) !== ($after['tournament'][1] ?? null)) {
            $diff['state'] = [(string) ($before['tournament'][1] ?? ''), (string) ($after['tournament'][1] ?? '')];
        }
        foreach ($after['matches'] as $id => $m) {
            if (($before['matches'][$id] ?? null) !== $m) {
                $diff['matches'][$id] = ['before' => $before['matches'][$id] ?? null, 'after' => $m];
            }
        }
        foreach ($before['matches'] as $id => $m) {
            if (!isset($after['matches'][$id])) {
                $diff['matches'][$id] = ['before' => $m, 'after' => null];
            }
        }

        return $diff;
    }
}
