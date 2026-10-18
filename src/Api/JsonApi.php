<?php

namespace ChallongeTournaments\Api;

/**
 * Turns JSON:API resources into the plain {id, attributes} arrays the package works with, and reads the fields of
 * Challonge's models defensively (several of them are not in the published schema, see docs/challonge-api-v2.1.md,
 * "UNCLEAR").
 */
final class JsonApi
{
    /**
     * @return array{id: string, attributes: array}
     */
    public static function resource(array $resource): array
    {
        $attributes = isset($resource['attributes']) && is_array($resource['attributes']) ? $resource['attributes'] : [];
        // the player relationships of a match sit inside the attributes, but may also sit next to them
        if (isset($resource['relationships']) && is_array($resource['relationships']) && !isset($attributes['relationships'])) {
            $attributes['relationships'] = $resource['relationships'];
        }

        return ['id' => (string) ($resource['id'] ?? ''), 'attributes' => $attributes];
    }

    /**
     * @return array<int, array{id: string, attributes: array}>
     */
    public static function collection(array $body): array
    {
        $data = $body['data'] ?? $body;
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['id'])) {
            return [self::resource($data)];
        }
        $resources = [];
        foreach ($data as $resource) {
            if (is_array($resource)) {
                $resources[] = self::resource($resource);
            }
        }

        return $resources;
    }

    /**
     * The messages of an error response: {"errors":[{detail}]} or a bare array of such items.
     *
     * @return string[]
     */
    public static function errors($body): array
    {
        if (!is_array($body)) {
            return [];
        }
        $items = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : $body;
        $messages = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $message = (string) ($item['detail'] ?? $item['title'] ?? $item['message'] ?? '');
            if ($message === '') {
                continue;
            }
            $pointer = $item['source']['pointer'] ?? null;
            if (is_string($pointer) && $pointer !== '') {
                $message = trim(str_replace('/data/attributes/', '', $pointer), '/') . ': ' . $message;
            }
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * The ID of a side of a match: relationships.player1.data.id or a flat player1_id.
     */
    public static function playerID(array $attributes, int $side): ?string
    {
        $id = $attributes['relationships']['player' . $side]['data']['id'] ?? $attributes['player' . $side . '_id'] ?? null;

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    /**
     * The sets of a match as [[p1, p2], ...] from score_in_sets, points_by_participant or scores_csv.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function sets(array $attributes, ?string $player1ID, ?string $player2ID): array
    {
        if (isset($attributes['score_in_sets']) && is_array($attributes['score_in_sets'])) {
            $sets = [];
            foreach ($attributes['score_in_sets'] as $set) {
                if (is_array($set) && count($set) >= 2) {
                    $sets[] = [(int) $set[0], (int) $set[1]];
                }
            }
            if ($sets !== []) {
                return $sets;
            }
        }
        if (isset($attributes['points_by_participant']) && is_array($attributes['points_by_participant'])) {
            $byPlayer = [];
            foreach ($attributes['points_by_participant'] as $entry) {
                if (is_array($entry) && isset($entry['participant_id'])) {
                    $byPlayer[(string) $entry['participant_id']] = array_map('intval', (array) ($entry['scores'] ?? []));
                }
            }
            $p1 = $player1ID !== null ? ($byPlayer[$player1ID] ?? []) : [];
            $p2 = $player2ID !== null ? ($byPlayer[$player2ID] ?? []) : [];
            $sets = [];
            for ($i = 0, $n = max(count($p1), count($p2)); $i < $n; $i++) {
                $sets[] = [(int) ($p1[$i] ?? 0), (int) ($p2[$i] ?? 0)];
            }
            if ($sets !== []) {
                return $sets;
            }
        }
        if (!empty($attributes['scores_csv'])) {
            return \ChallongeTournaments\Entity\TournamentMatch::parseScores((string) $attributes['scores_csv']);
        }

        return [];
    }

    /**
     * "3-1,2-3" from sets.
     *
     * @param array<int, array{0: int, 1: int}> $sets
     */
    public static function setsToCsv(array $sets): ?string
    {
        if ($sets === []) {
            return null;
        }

        return implode(',', array_map(static function (array $set) {
            return $set[0] . '-' . $set[1];
        }, $sets));
    }

    /**
     * The body of "Update Match" for a result: per participant its set scores, the winner advancing.
     *
     * @param array<int, array{0: int, 1: int}> $sets
     */
    public static function matchResultBody(string $player1ID, string $player2ID, string $winnerID, array $sets): array
    {
        $p1 = $p2 = [];
        foreach ($sets as $set) {
            $p1[] = (int) $set[0];
            $p2[] = (int) $set[1];
        }
        $match = [
            ['participant_id' => (string) $player1ID, 'score_set' => implode(',', $p1)],
            ['participant_id' => (string) $player2ID, 'score_set' => implode(',', $p2)],
        ];
        $tie = $winnerID === '';
        foreach ($match as &$entry) {
            if (!$tie && $entry['participant_id'] === (string) $winnerID) {
                $entry['advancing'] = true;
            }
        }
        unset($entry);

        return ['data' => ['type' => 'match', 'attributes' => ['match' => $match, 'tie' => $tie]]];
    }

    public static function date($value): ?\DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $date = new \DateTime($value);
            $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));

            return $date;
        } catch (\Exception $e) {
            return null;
        }
    }
}
