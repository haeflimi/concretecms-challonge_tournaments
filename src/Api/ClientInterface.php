<?php

namespace ChallongeTournaments\Api;

/**
 * The part of the Challonge API v2.1 this package uses. Every method returns plain arrays of the resource's
 * attributes (JSON:API `data.attributes` plus `id`), so the rest of the package never sees the envelope.
 *
 * Tournament identifiers are the Challonge ID or the URL slug; community tournaments are addressed through the
 * community subdomain (Resource Scoping).
 *
 * @see docs/challonge-api-v2.1.md
 */
interface ClientInterface
{
    /**
     * @return array{id: string, attributes: array} the tournament
     *
     * @throws ApiException
     */
    public function getTournament(string $tournament, ?string $community = null): array;

    /**
     * @return array<int, array{id: string, attributes: array}> all participants (all pages)
     *
     * @throws ApiException
     */
    public function listParticipants(string $tournament, ?string $community = null): array;

    /**
     * @return array<int, array{id: string, attributes: array}> all matches (all pages)
     *
     * @throws ApiException
     */
    public function listMatches(string $tournament, ?string $community = null): array;

    /**
     * Adds participants in one request.
     *
     * @param array<int, array{name: string, seed?: int, misc?: string, email?: string, username?: string}> $participants
     *
     * @return array<int, array{id: string, attributes: array}> the created participants
     *
     * @throws ApiException
     */
    public function bulkCreateParticipants(string $tournament, array $participants, ?string $community = null): array;

    /**
     * @param array{name?: string, seed?: int, misc?: string} $attributes
     *
     * @return array{id: string, attributes: array}
     *
     * @throws ApiException
     */
    public function updateParticipant(string $tournament, string $participantID, array $attributes, ?string $community = null): array;

    /**
     * @throws ApiException
     */
    public function deleteParticipant(string $tournament, string $participantID, ?string $community = null): void;

    /**
     * Removes every participant (only while the tournament is pending).
     *
     * @throws ApiException
     */
    public function clearParticipants(string $tournament, ?string $community = null): void;

    /**
     * Shuffles the seeds (only while the tournament is pending).
     *
     * @throws ApiException
     */
    public function randomizeParticipants(string $tournament, ?string $community = null): void;

    /**
     * Writes a result: the winner and the sets, e.g. "3-1,2-3,3-2" (player 1 first). An empty winner with scores
     * updates the scores of an open match without completing it.
     *
     * @return array{id: string, attributes: array} the updated match
     *
     * @throws ApiException
     */
    public function reportMatch(string $tournament, string $matchID, string $winnerID, string $scoresCsv, ?string $community = null): array;

    /**
     * @param string $state one of the MatchState::* values (mark as underway, unmark, reopen)
     *
     * @return array{id: string, attributes: array}
     *
     * @throws ApiException
     */
    public function changeMatchState(string $tournament, string $matchID, string $state, ?string $community = null): array;

    /**
     * @param string $state one of the TournamentState::* values (start, finalize, reset, ...)
     *
     * @return array{id: string, attributes: array}
     *
     * @throws ApiException
     */
    public function changeTournamentState(string $tournament, string $state, ?string $community = null): array;

    /**
     * Whether this client talks to challonge.com (false for the fake client).
     */
    public function isLive(): bool;
}
