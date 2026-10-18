<?php

namespace ChallongeTournaments\Tournament;

use ChallongeTournaments\Api\ApiException;
use ChallongeTournaments\Api\ClientInterface;
use ChallongeTournaments\Entity\Tournament;
use ChallongeTournaments\Sync\TournamentSync;
use Concrete\Core\Error\UserMessageException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Linking tournaments to the site and their settings. Challonge owns the tournament itself.
 */
class TournamentService
{
    /** @var EntityManagerInterface */
    protected $em;

    /** @var TournamentRepository */
    protected $repository;

    /** @var ClientInterface */
    protected $client;

    /** @var TournamentSync */
    protected $sync;

    public function __construct(EntityManagerInterface $em, TournamentRepository $repository, ClientInterface $client, TournamentSync $sync)
    {
        $this->em = $em;
        $this->repository = $repository;
        $this->client = $client;
        $this->sync = $sync;
    }

    /**
     * "https://challonge.com/lan35_cs2", "https://turicane.challonge.com/de/lan35_cs2", "lan35_cs2" or a numeric
     * ID: the slug (or ID) and the community subdomain.
     *
     * @return array{0: string, 1: string|null}
     *
     * @throws UserMessageException
     */
    public static function parseReference(string $reference, ?string $defaultCommunity = null): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new UserMessageException(t('Enter the URL of the tournament on Challonge.'));
        }
        $community = $defaultCommunity !== null && $defaultCommunity !== '' ? $defaultCommunity : null;
        if (preg_match('~^(?:https?://)?([a-z0-9-]+\.)?challonge\.com(/.*)?$~i', $reference, $m)) {
            $sub = isset($m[1]) ? strtolower(rtrim($m[1], '.')) : '';
            if ($sub !== '' && $sub !== 'www') {
                $community = $sub;
            }
            $segments = array_values(array_filter(explode('/', $m[2] ?? ''), 'strlen'));
            // a locale prefix (challonge.com/de/<slug>) and the module / standings suffixes
            if ($segments !== [] && preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $segments[0]) && count($segments) > 1) {
                array_shift($segments);
            }
            if ($segments === []) {
                throw new UserMessageException(t('The URL does not name a tournament.'));
            }
            $slug = $segments[0];
        } else {
            $slug = $reference;
        }
        $slug = preg_replace('/[?#].*$/', '', $slug);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
            throw new UserMessageException(t('"%s" is not a Challonge tournament URL or ID.', $slug));
        }

        return [$slug, $community];
    }

    /**
     * Links a tournament: fetched from Challonge, stored with its participants and matches.
     *
     * @throws UserMessageException
     */
    public function link(string $reference, ?string $defaultCommunity, ?int $eventID, ?int $poolID, int $uID): Tournament
    {
        [$slug, $community] = self::parseReference($reference, $defaultCommunity);
        try {
            $resource = $this->client->getTournament($slug, $community);
        } catch (ApiException $e) {
            if ($e->isNotFound()) {
                throw new UserMessageException(t('Challonge does not know a tournament "%s"%s.', $slug, $community ? ' ' . t('in the community "%s"', $community) : ''));
            }
            throw new UserMessageException($e->getMessage());
        }
        $challongeID = (string) $resource['id'];
        if ($challongeID === '') {
            throw new UserMessageException(t('Challonge answered without a tournament ID.'));
        }
        $existing = $this->repository->getByChallongeID($challongeID);
        if ($existing !== null) {
            throw new UserMessageException(t('The tournament "%s" is already linked.', $existing->getName()));
        }
        $tournament = new Tournament();
        $tournament->setChallongeID($challongeID);
        $tournament->setCommunity($community);
        $tournament->setEventID($eventID);
        $tournament->setPoolID($poolID);
        $tournament->setCreatedBy($uID);
        $tournament->setPosition($this->nextPosition($eventID));
        $this->sync->applyTournament($tournament, $resource);
        if ($tournament->getSlug() === '') {
            $tournament->setSlug($slug);
        }
        $this->em->persist($tournament);
        $this->em->flush();
        $this->sync->sync($tournament, false);

        return $tournament;
    }

    /**
     * @param array{eventID?: int|null, poolID?: int|null, visible?: bool, reportingEnabled?: bool, moderatorGroupID?: int|null, position?: int} $data
     */
    public function update(Tournament $tournament, array $data): void
    {
        if (array_key_exists('eventID', $data)) {
            $tournament->setEventID($data['eventID'] !== null ? (int) $data['eventID'] : null);
        }
        if (array_key_exists('poolID', $data)) {
            $tournament->setPoolID($data['poolID'] !== null ? (int) $data['poolID'] : null);
        }
        if (array_key_exists('visible', $data)) {
            $tournament->setVisible((bool) $data['visible']);
        }
        if (array_key_exists('reportingEnabled', $data)) {
            $tournament->setReportingEnabled((bool) $data['reportingEnabled']);
        }
        if (array_key_exists('moderatorGroupID', $data)) {
            $tournament->setModeratorGroupID($data['moderatorGroupID'] !== null ? (int) $data['moderatorGroupID'] : null);
        }
        if (array_key_exists('position', $data)) {
            $tournament->setPosition((int) $data['position']);
        }
        $this->em->flush();
    }

    /**
     * Removes the link (and the cached participants, matches and reports). Challonge is not touched.
     */
    public function unlink(Tournament $tournament): void
    {
        $this->em->remove($tournament);
        $this->em->flush();
    }

    /**
     * @throws UserMessageException
     */
    public function changeState(Tournament $tournament, string $state): void
    {
        try {
            $resource = $this->client->changeTournamentState($tournament->getChallongeID(), $state, $tournament->getCommunity());
        } catch (ApiException $e) {
            throw new UserMessageException($e->getMessage());
        }
        $this->sync->applyTournament($tournament, $resource);
        $this->em->flush();
        $this->sync->sync($tournament, false);
    }

    protected function nextPosition(?int $eventID): int
    {
        $siblings = $eventID ? $this->repository->getByEvent($eventID, false) : $this->repository->getUnassigned(false);
        $max = 0;
        foreach ($siblings as $sibling) {
            $max = max($max, $sibling->getPosition());
        }

        return $max + 1;
    }
}
