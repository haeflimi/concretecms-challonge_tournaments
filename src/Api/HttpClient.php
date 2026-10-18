<?php

namespace ChallongeTournaments\Api;

use ChallongeTournaments\Config\Settings;
use Concrete\Core\Http\Client\Client;
use Concrete\Core\Logging\Channels;
use Concrete\Core\Logging\LoggerFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * The Challonge API v2.1 over HTTP with a v1 API key. Free apps have 500 requests per month: every call counts, so
 * callers sync on a schedule and cache (see TournamentSync), never per page view.
 */
class HttpClient implements ClientInterface
{
    const BASE_URL = 'https://api.challonge.com/v2.1';
    const PER_PAGE = 100;

    /** @var Client */
    protected $http;

    /** @var Settings */
    protected $settings;

    /** @var LoggerInterface */
    protected $logger;

    /** @var int requests sent by this instance */
    protected $requestCount = 0;

    public function __construct(Client $http, Settings $settings, LoggerFactory $loggerFactory)
    {
        $this->http = $http;
        $this->settings = $settings;
        $this->logger = $loggerFactory->createLogger(Channels::CHANNEL_APPLICATION);
    }

    public function isLive(): bool
    {
        return true;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function getTournament(string $tournament, ?string $community = null): array
    {
        $body = $this->request('GET', $this->tournamentPath($tournament, $community) . '.json');

        return JsonApi::resource(is_array($body['data'] ?? null) ? $body['data'] : []);
    }

    public function listParticipants(string $tournament, ?string $community = null): array
    {
        return $this->paginate($this->tournamentPath($tournament, $community) . '/participants.json');
    }

    public function listMatches(string $tournament, ?string $community = null): array
    {
        return $this->paginate($this->tournamentPath($tournament, $community) . '/matches.json');
    }

    public function bulkCreateParticipants(string $tournament, array $participants, ?string $community = null): array
    {
        $created = [];
        // bulk_add takes 20 participants at most
        foreach (array_chunk(array_values($participants), 20) as $chunk) {
            $body = $this->request('POST', $this->tournamentPath($tournament, $community) . '/participants/bulk_add.json', [
                'data' => ['type' => 'Participants', 'attributes' => ['participants' => array_map([$this, 'participantAttributes'], $chunk)]],
            ]);
            foreach (JsonApi::collection($body) as $resource) {
                $created[] = $resource;
            }
        }

        return $created;
    }

    public function updateParticipant(string $tournament, string $participantID, array $attributes, ?string $community = null): array
    {
        $body = $this->request('PUT', $this->tournamentPath($tournament, $community) . '/participants/' . rawurlencode($participantID) . '.json', [
            'data' => ['type' => 'participant', 'attributes' => $this->participantAttributes($attributes)],
        ]);

        return JsonApi::resource(is_array($body['data'] ?? null) ? $body['data'] : []);
    }

    public function deleteParticipant(string $tournament, string $participantID, ?string $community = null): void
    {
        $this->request('DELETE', $this->tournamentPath($tournament, $community) . '/participants/' . rawurlencode($participantID) . '.json');
    }

    public function clearParticipants(string $tournament, ?string $community = null): void
    {
        $this->request('DELETE', $this->tournamentPath($tournament, $community) . '/participants/clear.json');
    }

    public function randomizeParticipants(string $tournament, ?string $community = null): void
    {
        $this->request('POST', $this->tournamentPath($tournament, $community) . '/participants/randomize.json');
    }

    public function reportMatch(string $tournament, string $matchID, string $winnerID, string $scoresCsv, ?string $community = null): array
    {
        $match = $this->getMatch($tournament, $matchID, $community);
        $player1 = JsonApi::playerID($match['attributes'], 1);
        $player2 = JsonApi::playerID($match['attributes'], 2);
        if ($player1 === null || $player2 === null) {
            throw new ApiException(t('Both sides of the match must be decided before a result can be reported.'), 422);
        }
        $sets = \ChallongeTournaments\Entity\TournamentMatch::parseScores($scoresCsv);
        if ($sets === []) {
            throw new ApiException(t('The scores are not in the form "3-1,2-3".'), 422);
        }
        $body = $this->request('PUT', $this->tournamentPath($tournament, $community) . '/matches/' . rawurlencode($matchID) . '.json',
            JsonApi::matchResultBody($player1, $player2, $winnerID, $sets));

        return JsonApi::resource(is_array($body['data'] ?? null) ? $body['data'] : []);
    }

    public function changeMatchState(string $tournament, string $matchID, string $state, ?string $community = null): array
    {
        if (!in_array($state, MatchState::ALL, true)) {
            throw new ApiException(t('Unknown match state change: %s', $state), 422);
        }
        $body = $this->request('PUT', $this->tournamentPath($tournament, $community) . '/matches/' . rawurlencode($matchID) . '/change_state.json', [
            'data' => ['type' => 'MatchState', 'attributes' => ['state' => $state]],
        ]);

        return JsonApi::resource(is_array($body['data'] ?? null) ? $body['data'] : []);
    }

    public function changeTournamentState(string $tournament, string $state, ?string $community = null): array
    {
        if (!in_array($state, TournamentState::ALL, true)) {
            throw new ApiException(t('Unknown tournament state change: %s', $state), 422);
        }
        $body = $this->request('PUT', $this->tournamentPath($tournament, $community) . '/change_state.json', [
            'data' => ['type' => 'TournamentState', 'attributes' => ['state' => $state]],
        ]);

        return JsonApi::resource(is_array($body['data'] ?? null) ? $body['data'] : []);
    }

    /**
     * @return array{id: string, attributes: array}
     */
    public function getMatch(string $tournament, string $matchID, ?string $community = null): array
    {
        $body = $this->request('GET', $this->tournamentPath($tournament, $community) . '/matches/' . rawurlencode($matchID) . '.json');

        return JsonApi::resource(is_array($body['data'] ?? null) ? $body['data'] : []);
    }

    protected function tournamentPath(string $tournament, ?string $community): string
    {
        $prefix = $community !== null && $community !== '' ? '/communities/' . rawurlencode($community) : '';

        return $prefix . '/tournaments/' . rawurlencode($tournament);
    }

    /**
     * @return array<int, array{id: string, attributes: array}>
     */
    protected function paginate(string $path): array
    {
        $all = [];
        for ($page = 1; $page <= 50; $page++) {
            $body = $this->request('GET', $path, null, ['page' => $page, 'per_page' => self::PER_PAGE]);
            $resources = JsonApi::collection($body);
            foreach ($resources as $resource) {
                $all[] = $resource;
            }
            // no pagination metadata is documented: stop at a short page
            if (count($resources) < self::PER_PAGE) {
                break;
            }
        }

        return $all;
    }

    protected function participantAttributes(array $participant): array
    {
        $attributes = [];
        foreach (['name', 'seed', 'misc', 'email', 'username'] as $key) {
            if (isset($participant[$key]) && $participant[$key] !== '' && $participant[$key] !== null) {
                $attributes[$key] = $key === 'seed' ? (int) $participant[$key] : (string) $participant[$key];
            }
        }

        return $attributes;
    }

    /**
     * @throws ApiException
     */
    protected function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $key = $this->settings->getApiKey();
        if ($key === '') {
            throw new ApiException(t('No Challonge API key is configured.'), 401);
        }
        $options = [
            'headers' => [
                'Content-Type' => 'application/vnd.api+json',
                'Accept' => 'application/json',
                'Authorization-Type' => 'v1',
                'Authorization' => $key,
                'User-Agent' => 'concretecms-challonge_tournaments',
            ],
            'http_errors' => false,
            'timeout' => 20,
            'connect_timeout' => 10,
        ];
        if ($json !== null) {
            $options['body'] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($query !== []) {
            $options['query'] = $query;
        }
        $this->requestCount++;
        try {
            $response = $this->http->request($method, self::BASE_URL . $path, $options);
        } catch (RequestException $e) {
            $this->logger->error('Challonge request failed: {method} {path}: {error}', ['method' => $method, 'path' => $path, 'error' => $e->getMessage()]);
            throw new ApiException(t('Challonge could not be reached: %s', $e->getMessage()), 0, [], $e);
        } catch (GuzzleException $e) {
            throw new ApiException(t('Challonge could not be reached: %s', $e->getMessage()), 0, [], $e);
        }
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $body = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) {
            $body = [];
        }
        if ($status >= 400) {
            $errors = JsonApi::errors($body);
            $message = $errors !== [] ? implode(' ', $errors) : $this->defaultMessage($status);
            $this->logger->warning('Challonge {method} {path} answered {status}: {message}', ['method' => $method, 'path' => $path, 'status' => $status, 'message' => $message]);
            throw new ApiException($message, $status, $errors);
        }

        return $body;
    }

    protected function defaultMessage(int $status): string
    {
        switch ($status) {
            case 401:
                return t('Challonge rejected the API key.');
            case 403:
                return t('The API key may not access this tournament.');
            case 404:
                return t('Challonge does not know this tournament, participant or match.');
            case 406:
            case 415:
                return t('Challonge rejected the request format.');
            case 422:
                return t('Challonge rejected the request as invalid.');
            case 429:
                return t('The Challonge API limit of the app is used up (429 Too Many Requests).');
        }

        return t('Challonge answered with HTTP %s.', $status);
    }
}
