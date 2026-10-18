<?php

namespace ChallongeTournaments\Api;

use ChallongeTournaments\Entity\TournamentMatch;

/**
 * An in-memory Challonge for development: the same interface as the HTTP client, the state kept in a JSON file under
 * application/files/challonge_fake. Knows single elimination (byes included) and round robin brackets; double
 * elimination and swiss are treated as single elimination / round robin. Fixtures: dev/fixtures/fake-tournaments.php.
 */
class FakeClient implements ClientInterface
{
    const DIR = 'challonge_fake';
    const FILE = 'state.json';

    /** @var array|null */
    protected $state;

    /** @var string */
    protected $path;

    /** @var string */
    protected $fixtures;

    public function __construct(?string $path = null, ?string $fixtures = null)
    {
        $this->path = $path ?: rtrim(DIR_FILES_UPLOADED_STANDARD, '/') . '/' . self::DIR . '/' . self::FILE;
        $this->fixtures = $fixtures ?: dirname(__DIR__, 2) . '/dev/fixtures/fake-tournaments.php';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function getStatePath(): string
    {
        return $this->path;
    }

    /**
     * Throws the state away; the fixtures are loaded again on the next call.
     */
    public function reset(): void
    {
        $this->state = null;
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * The slugs of the fake tournaments, for the dashboard hint and the dev script.
     *
     * @return string[]
     */
    public function getSlugs(): array
    {
        return array_keys($this->load()['tournaments']);
    }

    public function getTournament(string $tournament, ?string $community = null): array
    {
        $t = &$this->find($tournament);

        return $this->tournamentResource($t);
    }

    public function listParticipants(string $tournament, ?string $community = null): array
    {
        $t = &$this->find($tournament);
        $list = [];
        foreach ($t['participants'] as $p) {
            $list[] = $this->participantResource($p);
        }
        usort($list, static function ($a, $b) {
            return ($a['attributes']['seed'] ?? 0) <=> ($b['attributes']['seed'] ?? 0);
        });

        return $list;
    }

    public function listMatches(string $tournament, ?string $community = null): array
    {
        $t = &$this->find($tournament);
        $list = [];
        foreach ($t['matches'] as $m) {
            $list[] = $this->matchResource($m);
        }

        return $list;
    }

    public function bulkCreateParticipants(string $tournament, array $participants, ?string $community = null): array
    {
        $t = &$this->find($tournament);
        if ($t['attributes']['state'] !== 'pending') {
            throw new ApiException(t('Participants can only be added while the tournament is pending.'), 422);
        }
        $created = [];
        foreach ($participants as $input) {
            if (!isset($input['name']) || trim((string) $input['name']) === '') {
                throw new ApiException('name: can\'t be blank', 422, ['name: can\'t be blank']);
            }
            foreach ($t['participants'] as $existing) {
                if (mb_strtolower($existing['name']) === mb_strtolower(trim((string) $input['name']))) {
                    throw new ApiException(t('name: "%s" has already been taken', $input['name']), 422);
                }
            }
            $id = (string) $this->nextID();
            $p = [
                'id' => $id,
                'name' => trim((string) $input['name']),
                'seed' => isset($input['seed']) ? (int) $input['seed'] : count($t['participants']) + 1,
                'misc' => isset($input['misc']) ? (string) $input['misc'] : null,
                'username' => $input['username'] ?? null,
                'final_rank' => null,
                'active' => true,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ];
            $t['participants'][$id] = $p;
            $created[] = $this->participantResource($p);
        }
        $this->renumberSeeds($t);
        $t['attributes']['participants_count'] = count($t['participants']);
        $this->save();

        return $created;
    }

    public function updateParticipant(string $tournament, string $participantID, array $attributes, ?string $community = null): array
    {
        $t = &$this->find($tournament);
        if (!isset($t['participants'][$participantID])) {
            throw new ApiException(t('Challonge does not know this tournament, participant or match.'), 404);
        }
        $p = &$t['participants'][$participantID];
        foreach (['name', 'misc', 'username'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $p[$key] = $attributes[$key] !== null ? (string) $attributes[$key] : null;
            }
        }
        if (isset($attributes['seed'])) {
            if ($t['attributes']['state'] !== 'pending') {
                throw new ApiException(t('Seeds can only be changed while the tournament is pending.'), 422);
            }
            $this->moveSeed($t, $participantID, (int) $attributes['seed']);
        }
        $p['updated_at'] = $this->now();
        $this->save();

        return $this->participantResource($p);
    }

    public function deleteParticipant(string $tournament, string $participantID, ?string $community = null): void
    {
        $t = &$this->find($tournament);
        if (!isset($t['participants'][$participantID])) {
            throw new ApiException(t('Challonge does not know this tournament, participant or match.'), 404);
        }
        if ($t['attributes']['state'] === 'pending') {
            unset($t['participants'][$participantID]);
            $this->renumberSeeds($t);
        } else {
            $t['participants'][$participantID]['active'] = false;
        }
        $t['attributes']['participants_count'] = count($t['participants']);
        $this->save();
    }

    public function clearParticipants(string $tournament, ?string $community = null): void
    {
        $t = &$this->find($tournament);
        if ($t['attributes']['state'] !== 'pending') {
            throw new ApiException(t('Participants can only be cleared while the tournament is pending.'), 422);
        }
        $t['participants'] = [];
        $t['attributes']['participants_count'] = 0;
        $this->save();
    }

    public function randomizeParticipants(string $tournament, ?string $community = null): void
    {
        $t = &$this->find($tournament);
        if ($t['attributes']['state'] !== 'pending') {
            throw new ApiException(t('Seeds can only be shuffled while the tournament is pending.'), 422);
        }
        $ids = array_keys($t['participants']);
        shuffle($ids);
        $seed = 1;
        foreach ($ids as $id) {
            $t['participants'][$id]['seed'] = $seed++;
        }
        $this->save();
    }

    public function reportMatch(string $tournament, string $matchID, string $winnerID, string $scoresCsv, ?string $community = null): array
    {
        $t = &$this->find($tournament);
        if (!isset($t['matches'][$matchID])) {
            throw new ApiException(t('Challonge does not know this tournament, participant or match.'), 404);
        }
        $m = &$t['matches'][$matchID];
        if ($m['state'] !== 'open') {
            throw new ApiException(t('Only open matches can be reported.'), 422);
        }
        $sets = TournamentMatch::parseScores($scoresCsv);
        if ($sets === []) {
            throw new ApiException(t('The scores are not in the form "3-1,2-3".'), 422);
        }
        if ($winnerID !== '' && $winnerID !== $m['player1_id'] && $winnerID !== $m['player2_id']) {
            throw new ApiException(t('The winner does not play in this match.'), 422);
        }
        $m['score_in_sets'] = $sets;
        $m['winner_id'] = $winnerID !== '' ? $winnerID : null;
        $m['loser_id'] = $winnerID !== '' ? ($winnerID === $m['player1_id'] ? $m['player2_id'] : $m['player1_id']) : null;
        $m['state'] = 'complete';
        $m['underway_at'] = null;
        $m['completed_at'] = $this->now();
        $m['updated_at'] = $this->now();
        $this->advance($t, $m);
        $this->refreshTournamentState($t);
        $this->save();

        return $this->matchResource($m);
    }

    public function changeMatchState(string $tournament, string $matchID, string $state, ?string $community = null): array
    {
        $t = &$this->find($tournament);
        if (!isset($t['matches'][$matchID])) {
            throw new ApiException(t('Challonge does not know this tournament, participant or match.'), 404);
        }
        $m = &$t['matches'][$matchID];
        switch ($state) {
            case MatchState::MARK_AS_UNDERWAY:
                if ($m['state'] !== 'open') {
                    throw new ApiException(t('Only open matches can be marked as underway.'), 422);
                }
                $m['underway_at'] = $this->now();
                break;
            case MatchState::UNMARK_AS_UNDERWAY:
                $m['underway_at'] = null;
                break;
            case MatchState::REOPEN:
                if ($m['state'] !== 'complete') {
                    throw new ApiException(t('Only complete matches can be reopened.'), 422);
                }
                $this->reopen($t, $m);
                $this->refreshTournamentState($t);
                break;
            default:
                throw new ApiException(t('Unknown match state change: %s', $state), 422);
        }
        $m['updated_at'] = $this->now();
        $this->save();

        return $this->matchResource($m);
    }

    public function changeTournamentState(string $tournament, string $state, ?string $community = null): array
    {
        $t = &$this->find($tournament);
        $current = $t['attributes']['state'];
        switch ($state) {
            case TournamentState::START:
                if (!in_array($current, ['pending', 'checked_in', 'group_stages_finalized'], true)) {
                    throw new ApiException(t('The tournament cannot be started from the state "%s".', $current), 422);
                }
                if (count($t['participants']) < 2) {
                    throw new ApiException(t('At least two participants are needed to start.'), 422);
                }
                $this->buildBracket($t);
                $t['attributes']['state'] = 'underway';
                $t['attributes']['started_at'] = $this->now();
                break;
            case TournamentState::FINALIZE:
                if ($current !== 'awaiting_review') {
                    throw new ApiException(t('The tournament can only be finalized once every match is reported.'), 422);
                }
                $this->finalRanks($t);
                $t['attributes']['state'] = 'complete';
                $t['attributes']['completed_at'] = $this->now();
                break;
            case TournamentState::RESET:
                $t['matches'] = [];
                foreach ($t['participants'] as &$p) {
                    $p['final_rank'] = null;
                    $p['active'] = true;
                }
                unset($p);
                $t['attributes']['state'] = 'pending';
                $t['attributes']['started_at'] = null;
                $t['attributes']['completed_at'] = null;
                break;
            case TournamentState::OPEN_PREDICTIONS:
            case TournamentState::PROCESS_CHECKIN:
            case TournamentState::ABORT_CHECKIN:
            case TournamentState::START_GROUP_STAGE:
            case TournamentState::FINALIZE_GROUP_STAGE:
            case TournamentState::RESET_GROUP_STAGE:
                throw new ApiException(t('The fake Challonge does not support "%s".', $state), 422);
            default:
                throw new ApiException(t('Unknown tournament state change: %s', $state), 422);
        }
        $t['attributes']['updated_at'] = $this->now();
        $this->save();

        return $this->tournamentResource($t);
    }

    // ---- state -------------------------------------------------------------------------------------------------

    protected function &load(): array
    {
        if ($this->state === null) {
            $state = null;
            if (is_file($this->path)) {
                $state = json_decode((string) file_get_contents($this->path), true);
            }
            if (!is_array($state) || !isset($state['tournaments'])) {
                $state = ['nextID' => 1000, 'tournaments' => []];
                if (is_file($this->fixtures)) {
                    $fixtures = include $this->fixtures;
                    foreach ((array) $fixtures as $fixture) {
                        $this->addFixture($state, $fixture);
                    }
                }
                $this->state = $state;
                $this->save();
            } else {
                $this->state = $state;
            }
        }

        return $this->state;
    }

    protected function save(): void
    {
        if ($this->state === null) {
            return;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($this->path, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    protected function &find(string $tournament): array
    {
        $state = &$this->load();
        $key = ltrim($tournament, '/');
        if (isset($state['tournaments'][$key])) {
            return $state['tournaments'][$key];
        }
        foreach ($state['tournaments'] as $slug => &$t) {
            if ((string) $t['id'] === $key) {
                return $t;
            }
        }
        unset($t);
        throw new ApiException(t('Challonge does not know this tournament, participant or match.'), 404);
    }

    protected function nextID(): int
    {
        $state = &$this->load();

        return $state['nextID']++;
    }

    protected function now(): string
    {
        return (new \DateTime())->format(DATE_ATOM);
    }

    /**
     * A fixture: ['url' => slug, 'name', 'tournament_type', 'game_name', 'starts_at', 'description', 'participants' => [names or ['name','misc']],
     * 'start' => bool, 'results' => [[identifier, winner index (1|2), scores]], 'finalize' => bool].
     */
    protected function addFixture(array &$state, array $fixture): void
    {
        $slug = (string) $fixture['url'];
        $id = (string) $state['nextID']++;
        $state['tournaments'][$slug] = [
            'id' => $id,
            'participants' => [],
            'matches' => [],
            'attributes' => [
                'name' => (string) ($fixture['name'] ?? $slug),
                'url' => $slug,
                'tournament_type' => (string) ($fixture['tournament_type'] ?? 'single elimination'),
                'state' => 'pending',
                'game_name' => $fixture['game_name'] ?? null,
                'starts_at' => $fixture['starts_at'] ?? null,
                'description' => $fixture['description'] ?? null,
                'private' => false,
                'group_stage_enabled' => false,
                'full_challonge_url' => 'https://challonge.com/' . $slug,
                'live_image_url' => 'https://challonge.com/' . $slug . '.svg',
                'participants_count' => 0,
                'started_at' => null,
                'completed_at' => null,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ],
        ];
        $t = &$state['tournaments'][$slug];
        $seed = 1;
        foreach ((array) ($fixture['participants'] ?? []) as $p) {
            $p = is_array($p) ? $p : ['name' => (string) $p];
            $pid = (string) $state['nextID']++;
            $t['participants'][$pid] = [
                'id' => $pid, 'name' => $p['name'], 'seed' => $seed++, 'misc' => $p['misc'] ?? null, 'username' => null,
                'final_rank' => null, 'active' => true, 'created_at' => $this->now(), 'updated_at' => $this->now(),
            ];
        }
        $t['attributes']['participants_count'] = count($t['participants']);
        if (!empty($fixture['start']) && count($t['participants']) >= 2) {
            // the bracket needs the ID counter: hand the state over temporarily
            $this->state = $state;
            $this->buildBracket($t);
            $state = $this->state;
            $t = &$state['tournaments'][$slug];
            $t['attributes']['state'] = 'underway';
            $t['attributes']['started_at'] = $this->now();
            foreach ((array) ($fixture['results'] ?? []) as [$identifier, $winnerSide, $scores]) {
                foreach ($t['matches'] as &$m) {
                    if ($m['identifier'] === $identifier && $m['state'] === 'open') {
                        $m['score_in_sets'] = TournamentMatch::parseScores($scores);
                        $m['winner_id'] = $winnerSide === 1 ? $m['player1_id'] : $m['player2_id'];
                        $m['loser_id'] = $winnerSide === 1 ? $m['player2_id'] : $m['player1_id'];
                        $m['state'] = 'complete';
                        $m['completed_at'] = $this->now();
                        $this->advance($t, $m);
                    }
                }
                unset($m);
            }
            if (!empty($fixture['underway'])) {
                foreach ($t['matches'] as &$m) {
                    if ($m['state'] === 'open' && in_array($m['identifier'], (array) $fixture['underway'], true)) {
                        $m['underway_at'] = $this->now();
                    }
                }
                unset($m);
            }
            $this->refreshTournamentState($t);
            if (!empty($fixture['finalize']) && $t['attributes']['state'] === 'awaiting_review') {
                $this->finalRanks($t);
                $t['attributes']['state'] = 'complete';
                $t['attributes']['completed_at'] = $this->now();
            }
        }
        $this->state = null;
    }

    // ---- brackets ----------------------------------------------------------------------------------------------

    protected function buildBracket(array &$t): void
    {
        $t['matches'] = [];
        $type = $t['attributes']['tournament_type'];
        if (in_array($type, ['round robin', 'swiss'], true)) {
            $this->buildRoundRobin($t);
        } else {
            $this->buildSingleElimination($t);
        }
    }

    protected function buildSingleElimination(array &$t): void
    {
        $participants = array_values($t['participants']);
        usort($participants, static function ($a, $b) {
            return $a['seed'] <=> $b['seed'];
        });
        $n = count($participants);
        $size = 1;
        while ($size < $n) {
            $size *= 2;
        }
        // standard seeding order of the first round (1 vs size, 2 vs size-1, ... interleaved)
        $order = [1];
        while (count($order) < $size) {
            $next = [];
            $m = count($order) * 2 + 1;
            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $m - $seed;
            }
            $order = $next;
        }
        $slots = [];
        foreach ($order as $seed) {
            $slots[] = $participants[$seed - 1]['id'] ?? null;
        }
        $rounds = (int) log($size, 2);
        $playOrder = 1;
        $identifier = 0;
        $previous = [];
        for ($round = 1; $round <= $rounds; $round++) {
            $current = [];
            $count = $size / (2 ** $round);
            for ($i = 0; $i < $count; $i++) {
                $id = (string) $this->nextID();
                $m = [
                    'id' => $id, 'state' => 'pending', 'round' => $round, 'identifier' => $this->identifier($identifier++),
                    'suggested_play_order' => $playOrder++, 'group_id' => null,
                    'player1_id' => null, 'player2_id' => null, 'winner_id' => null, 'loser_id' => null,
                    'score_in_sets' => [], 'underway_at' => null, 'scheduled_time' => null, 'completed_at' => null,
                    'next_match_id' => null, 'next_slot' => null,
                    'created_at' => $this->now(), 'updated_at' => $this->now(),
                ];
                if ($round === 1) {
                    $m['player1_id'] = $slots[$i * 2];
                    $m['player2_id'] = $slots[$i * 2 + 1];
                } else {
                    $previous[$i * 2]['next_match_id'] = $id;
                    $previous[$i * 2]['next_slot'] = 1;
                    $previous[$i * 2 + 1]['next_match_id'] = $id;
                    $previous[$i * 2 + 1]['next_slot'] = 2;
                }
                $current[] = $m;
            }
            foreach ($previous as $pm) {
                $t['matches'][$pm['id']] = $pm;
            }
            $previous = $current;
        }
        foreach ($previous as $pm) {
            $t['matches'][$pm['id']] = $pm;
        }
        // first round: open the matches with two players, walk the byes through
        foreach ($t['matches'] as &$m) {
            if ($m['round'] !== 1) {
                continue;
            }
            if ($m['player1_id'] !== null && $m['player2_id'] !== null) {
                $m['state'] = 'open';
            } elseif ($m['player1_id'] !== null || $m['player2_id'] !== null) {
                $m['state'] = 'complete';
                $m['winner_id'] = $m['player1_id'] ?? $m['player2_id'];
                $m['completed_at'] = $this->now();
                $this->advance($t, $m);
            } else {
                $m['state'] = 'complete';
            }
        }
        unset($m);
    }

    protected function buildRoundRobin(array &$t): void
    {
        $ids = array_keys($t['participants']);
        $playOrder = 1;
        $identifier = 0;
        $n = count($ids);
        // circle method: n-1 rounds (n rounds for odd n with a bye)
        $list = $ids;
        if ($n % 2 === 1) {
            $list[] = null;
        }
        $size = count($list);
        for ($round = 1; $round < $size; $round++) {
            for ($i = 0; $i < $size / 2; $i++) {
                $a = $list[$i];
                $b = $list[$size - 1 - $i];
                if ($a === null || $b === null) {
                    continue;
                }
                $id = (string) $this->nextID();
                $t['matches'][$id] = [
                    'id' => $id, 'state' => 'open', 'round' => $round, 'identifier' => $this->identifier($identifier++),
                    'suggested_play_order' => $playOrder++, 'group_id' => null,
                    'player1_id' => $a, 'player2_id' => $b, 'winner_id' => null, 'loser_id' => null,
                    'score_in_sets' => [], 'underway_at' => null, 'scheduled_time' => null, 'completed_at' => null,
                    'next_match_id' => null, 'next_slot' => null,
                    'created_at' => $this->now(), 'updated_at' => $this->now(),
                ];
            }
            // rotate all but the first
            $first = array_shift($list);
            array_unshift($list, array_pop($list));
            array_unshift($list, $first);
        }
    }

    protected function identifier(int $index): string
    {
        $s = '';
        do {
            $s = chr(65 + ($index % 26)) . $s;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $s;
    }

    protected function advance(array &$t, array &$m): void
    {
        if (empty($m['next_match_id']) || $m['winner_id'] === null || !isset($t['matches'][$m['next_match_id']])) {
            return;
        }
        $next = &$t['matches'][$m['next_match_id']];
        $next['player' . $m['next_slot'] . '_id'] = $m['winner_id'];
        if ($next['player1_id'] !== null && $next['player2_id'] !== null && $next['state'] === 'pending') {
            $next['state'] = 'open';
        }
        $next['updated_at'] = $this->now();
    }

    protected function reopen(array &$t, array &$m): void
    {
        if (!empty($m['next_match_id']) && isset($t['matches'][$m['next_match_id']])) {
            $next = &$t['matches'][$m['next_match_id']];
            if ($next['state'] === 'complete') {
                throw new ApiException(t('The following match is already complete; reopen it first.'), 422);
            }
            $next['player' . $m['next_slot'] . '_id'] = null;
            $next['state'] = 'pending';
            $next['underway_at'] = null;
        }
        $m['state'] = 'open';
        $m['winner_id'] = null;
        $m['loser_id'] = null;
        $m['completed_at'] = null;
        foreach ($t['participants'] as &$p) {
            $p['final_rank'] = null;
        }
        unset($p);
    }

    protected function refreshTournamentState(array &$t): void
    {
        if ($t['matches'] === []) {
            return;
        }
        foreach ($t['matches'] as $m) {
            if ($m['state'] !== 'complete') {
                if ($t['attributes']['state'] === 'awaiting_review') {
                    $t['attributes']['state'] = 'underway';
                }

                return;
            }
        }
        if ($t['attributes']['state'] === 'underway') {
            $t['attributes']['state'] = 'awaiting_review';
        }
    }

    protected function finalRanks(array &$t): void
    {
        $type = $t['attributes']['tournament_type'];
        if (in_array($type, ['round robin', 'swiss'], true)) {
            $wins = array_fill_keys(array_keys($t['participants']), 0);
            $diff = array_fill_keys(array_keys($t['participants']), 0);
            foreach ($t['matches'] as $m) {
                if ($m['winner_id'] !== null && isset($wins[$m['winner_id']])) {
                    $wins[$m['winner_id']]++;
                }
                foreach ($m['score_in_sets'] as [$a, $b]) {
                    if (isset($diff[$m['player1_id']])) {
                        $diff[$m['player1_id']] += $a - $b;
                    }
                    if (isset($diff[$m['player2_id']])) {
                        $diff[$m['player2_id']] += $b - $a;
                    }
                }
            }
            $ids = array_keys($t['participants']);
            usort($ids, static function ($a, $b) use ($wins, $diff) {
                return [$wins[$b], $diff[$b]] <=> [$wins[$a], $diff[$a]];
            });
            $rank = 1;
            foreach ($ids as $id) {
                $t['participants'][$id]['final_rank'] = $rank++;
            }

            return;
        }
        // single elimination: the winner of the last match first, then the losers by the round they lost in
        $maxRound = 0;
        foreach ($t['matches'] as $m) {
            $maxRound = max($maxRound, $m['round']);
        }
        foreach ($t['participants'] as &$p) {
            $p['final_rank'] = null;
        }
        unset($p);
        foreach ($t['matches'] as $m) {
            if ($m['round'] === $maxRound && $m['winner_id'] !== null) {
                $t['participants'][$m['winner_id']]['final_rank'] = 1;
            }
            if ($m['loser_id'] !== null && isset($t['participants'][$m['loser_id']])) {
                // losers of round r share rank 2^(maxRound - r) + 1
                $t['participants'][$m['loser_id']]['final_rank'] = (2 ** ($maxRound - $m['round'])) + 1;
            }
        }
    }

    protected function renumberSeeds(array &$t): void
    {
        $ids = array_keys($t['participants']);
        usort($ids, function ($a, $b) use ($t) {
            return [$t['participants'][$a]['seed'], (int) $a] <=> [$t['participants'][$b]['seed'], (int) $b];
        });
        $seed = 1;
        foreach ($ids as $id) {
            $t['participants'][$id]['seed'] = $seed++;
        }
    }

    protected function moveSeed(array &$t, string $participantID, int $seed): void
    {
        $ids = array_keys($t['participants']);
        usort($ids, function ($a, $b) use ($t) {
            return $t['participants'][$a]['seed'] <=> $t['participants'][$b]['seed'];
        });
        $ids = array_values(array_diff($ids, [$participantID]));
        array_splice($ids, max(0, min(count($ids), $seed - 1)), 0, [$participantID]);
        $s = 1;
        foreach ($ids as $id) {
            $t['participants'][$id]['seed'] = $s++;
        }
    }

    // ---- resources ---------------------------------------------------------------------------------------------

    protected function tournamentResource(array $t): array
    {
        $attributes = $t['attributes'];
        $attributes['participants_count'] = count($t['participants']);
        $attributes['timestamps'] = ['created_at' => $attributes['created_at'], 'updated_at' => $attributes['updated_at']];

        return ['id' => (string) $t['id'], 'attributes' => $attributes];
    }

    protected function participantResource(array $p): array
    {
        return ['id' => (string) $p['id'], 'attributes' => [
            'name' => $p['name'],
            'seed' => (int) $p['seed'],
            'group_id' => null,
            'tournament_id' => null,
            'username' => $p['username'],
            'final_rank' => $p['final_rank'],
            'states' => ['active' => (bool) $p['active']],
            'misc' => $p['misc'],
            'timestamps' => ['created_at' => $p['created_at'], 'updated_at' => $p['updated_at']],
        ]];
    }

    protected function matchResource(array $m): array
    {
        $sets = $m['score_in_sets'] ?? [];
        $wins = [0, 0];
        $points = [[], []];
        foreach ($sets as [$a, $b]) {
            $points[0][] = $a;
            $points[1][] = $b;
            if ($a > $b) {
                $wins[0]++;
            } elseif ($b > $a) {
                $wins[1]++;
            }
        }
        $relationships = [];
        foreach ([1, 2] as $side) {
            $relationships['player' . $side] = ['data' => $m['player' . $side . '_id'] !== null ? ['id' => (string) $m['player' . $side . '_id'], 'type' => 'participant'] : null];
        }

        return ['id' => (string) $m['id'], 'attributes' => [
            'state' => $m['state'],
            'round' => (int) $m['round'],
            'identifier' => $m['identifier'],
            'suggested_play_order' => $m['suggested_play_order'],
            'group_id' => $m['group_id'],
            'scores' => $sets !== [] ? $wins[0] . ' - ' . $wins[1] : null,
            'score_in_sets' => $sets,
            'points_by_participant' => [
                ['participant_id' => $m['player1_id'], 'scores' => $points[0]],
                ['participant_id' => $m['player2_id'], 'scores' => $points[1]],
            ],
            'winner_id' => $m['winner_id'] !== null ? (int) $m['winner_id'] : null,
            'loser_id' => $m['loser_id'] !== null ? (int) $m['loser_id'] : null,
            'underway_at' => $m['underway_at'],
            'scheduled_time' => $m['scheduled_time'],
            'completed_at' => $m['completed_at'],
            'relationships' => $relationships,
            'timestamps' => ['created_at' => $m['created_at'], 'updated_at' => $m['updated_at']],
        ]];
    }
}
