<?php

namespace ChallongeTournaments\Seeding;

use ChallongeTournaments\Api\ApiException;
use ChallongeTournaments\Api\ClientInterface;
use ChallongeTournaments\Entity\Participant;
use ChallongeTournaments\Entity\Tournament;
use ChallongeTournaments\Sync\TournamentSync;
use Concrete\Core\Application\Application;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\UserInfoRepository;

/**
 * Seeds a tournament from its team pool: the pool's teams (and single players, if the pool allows them) become
 * participants on Challonge, linked back through the participant's misc field ("team:12" / "user:5"). Only while the
 * tournament is pending; seeds are the order given (random by default).
 */
class Seeder
{
    /** @var Application */
    protected $app;

    /** @var ClientInterface */
    protected $client;

    /** @var TournamentSync */
    protected $sync;

    public function __construct(Application $app, ClientInterface $client, TournamentSync $sync)
    {
        $this->app = $app;
        $this->client = $client;
        $this->sync = $sync;
    }

    public function isAvailable(): bool
    {
        return class_exists(\TeamManager\Team\TeamPoolRepository::class);
    }

    /**
     * The seeding candidates of the tournament's pool, with the state on Challonge: key ("team:12" / "user:5"),
     * name, members, and the participant it already is (or null).
     *
     * @return Candidate[]
     */
    public function getCandidates(Tournament $tournament): array
    {
        if (!$this->isAvailable() || $tournament->getPoolID() === null) {
            return [];
        }
        $pools = $this->app->make(\TeamManager\Team\TeamPoolRepository::class);
        $pool = $pools->getByID($tournament->getPoolID());
        if ($pool === null) {
            return [];
        }
        $linked = [];
        foreach ($tournament->getParticipants() as $participant) {
            if ($participant->getTeamID() !== null) {
                $linked[Participant::MISC_TEAM . $participant->getTeamID()] = $participant;
            } elseif ($participant->getUserID() !== null) {
                $linked[Participant::MISC_USER . $participant->getUserID()] = $participant;
            }
        }
        $candidates = [];
        $teams = $this->app->make(\TeamManager\Team\TeamRepository::class);
        foreach ($teams->findAll($pool) as $team) {
            $members = [];
            foreach ($team->getMembers() as $member) {
                $members[] = $member->getUserName() . ($member->isCaptain() ? ' (C)' : '');
            }
            $key = Participant::MISC_TEAM . $team->getID();
            $candidates[$key] = new Candidate($key, $team->getDisplayName(), $members, $linked[$key] ?? null);
        }
        if ($pool->allowsSingles()) {
            $users = $this->app->make(UserInfoRepository::class);
            foreach ($pools->getSingles($pool) as $single) {
                $ui = $users->getByID($single->getUserID());
                if ($ui === null) {
                    continue;
                }
                $key = Participant::MISC_USER . $single->getUserID();
                $candidates[$key] = new Candidate($key, $ui->getUserName(), [], $linked[$key] ?? null);
            }
        }
        // participants on Challonge that are not (or no longer) in the pool
        foreach ($tournament->getParticipants() as $participant) {
            $key = $participant->getTeamID() !== null ? Participant::MISC_TEAM . $participant->getTeamID()
                : ($participant->getUserID() !== null ? Participant::MISC_USER . $participant->getUserID() : 'challonge:' . $participant->getChallongeID());
            if (!isset($candidates[$key])) {
                $candidates[$key] = new Candidate($key, $participant->getName(), [], $participant, true);
            }
        }

        return array_values($candidates);
    }

    /**
     * Pushes the chosen candidates to Challonge in the given order (seeds continue after the existing participants).
     *
     * @param string[] $keys candidate keys in seed order
     *
     * @return int the number of participants added
     *
     * @throws UserMessageException
     */
    public function seed(Tournament $tournament, array $keys): int
    {
        if (!$tournament->isPending()) {
            throw new UserMessageException(t('Participants can only be added while the tournament is pending.'));
        }
        $candidates = [];
        foreach ($this->getCandidates($tournament) as $candidate) {
            $candidates[$candidate->getKey()] = $candidate;
        }
        $names = [];
        foreach ($tournament->getParticipants() as $participant) {
            $names[mb_strtolower($participant->getName())] = true;
        }
        $seed = $tournament->getParticipants()->count();
        $payload = [];
        foreach ($keys as $key) {
            $candidate = $candidates[$key] ?? null;
            if ($candidate === null || $candidate->getParticipant() !== null || $candidate->isOrphan()) {
                continue;
            }
            $name = $this->uniqueName($candidate->getName(), $names);
            $names[mb_strtolower($name)] = true;
            $payload[] = ['name' => $name, 'seed' => ++$seed, 'misc' => $candidate->getKey()];
        }
        if ($payload === []) {
            return 0;
        }
        try {
            $this->client->bulkCreateParticipants($tournament->getChallongeID(), $payload, $tournament->getCommunity());
        } catch (ApiException $e) {
            throw new UserMessageException(t('Challonge refused the participants: %s', $e->getMessage()));
        }
        $this->sync->sync($tournament, false);

        return count($payload);
    }

    /**
     * @throws UserMessageException
     */
    public function remove(Tournament $tournament, Participant $participant): void
    {
        if (!$tournament->isPending()) {
            throw new UserMessageException(t('Participants can only be removed while the tournament is pending.'));
        }
        try {
            $this->client->deleteParticipant($tournament->getChallongeID(), $participant->getChallongeID(), $tournament->getCommunity());
        } catch (ApiException $e) {
            throw new UserMessageException($e->getMessage());
        }
        $this->sync->sync($tournament, false);
    }

    /**
     * @throws UserMessageException
     */
    public function clear(Tournament $tournament): void
    {
        if (!$tournament->isPending()) {
            throw new UserMessageException(t('Participants can only be removed while the tournament is pending.'));
        }
        try {
            $this->client->clearParticipants($tournament->getChallongeID(), $tournament->getCommunity());
        } catch (ApiException $e) {
            throw new UserMessageException($e->getMessage());
        }
        $this->sync->sync($tournament, false);
    }

    /**
     * @throws UserMessageException
     */
    public function shuffle(Tournament $tournament): void
    {
        if (!$tournament->isPending()) {
            throw new UserMessageException(t('Seeds can only be shuffled while the tournament is pending.'));
        }
        try {
            $this->client->randomizeParticipants($tournament->getChallongeID(), $tournament->getCommunity());
        } catch (ApiException $e) {
            throw new UserMessageException($e->getMessage());
        }
        $this->sync->sync($tournament, false);
    }

    /**
     * Moves a participant to a seed (1-based) on Challonge.
     *
     * @throws UserMessageException
     */
    public function reseed(Tournament $tournament, Participant $participant, int $seed): void
    {
        if (!$tournament->isPending()) {
            throw new UserMessageException(t('Seeds can only be changed while the tournament is pending.'));
        }
        try {
            $this->client->updateParticipant($tournament->getChallongeID(), $participant->getChallongeID(), ['seed' => max(1, $seed)], $tournament->getCommunity());
        } catch (ApiException $e) {
            throw new UserMessageException($e->getMessage());
        }
        $this->sync->sync($tournament, false);
    }

    protected function uniqueName(string $name, array $taken): string
    {
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 60) : t('Team');
        $candidate = $name;
        $n = 2;
        while (isset($taken[mb_strtolower($candidate)])) {
            $candidate = $name . ' (' . $n++ . ')';
        }

        return $candidate;
    }
}
