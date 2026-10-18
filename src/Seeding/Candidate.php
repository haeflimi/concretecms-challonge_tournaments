<?php

namespace ChallongeTournaments\Seeding;

use ChallongeTournaments\Entity\Participant;

/**
 * A team or single player of the pool as a seeding candidate, and whether it is already on Challonge.
 */
class Candidate
{
    /** @var string */
    protected $key;

    /** @var string */
    protected $name;

    /** @var string[] */
    protected $members;

    /** @var Participant|null */
    protected $participant;

    /** @var bool on Challonge, but not (or no longer) in the pool */
    protected $orphan;

    public function __construct(string $key, string $name, array $members, ?Participant $participant, bool $orphan = false)
    {
        $this->key = $key;
        $this->name = $name;
        $this->members = $members;
        $this->participant = $participant;
        $this->orphan = $orphan;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function isTeam(): bool
    {
        return strpos($this->key, Participant::MISC_TEAM) === 0;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string[]
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function isSeeded(): bool
    {
        return $this->participant !== null;
    }

    public function isOrphan(): bool
    {
        return $this->orphan;
    }
}
