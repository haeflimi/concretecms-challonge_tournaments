<?php

namespace ChallongeTournaments\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * A participant of a tournament as Challonge knows it, with the link to the team (or single player) it was seeded
 * from. The link survives re-syncs through the participant's `misc` field on Challonge ("team:12" / "user:5").
 *
 * @ORM\Entity()
 * @ORM\Table(name="ctParticipant", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="ctParticipant_challonge", columns={"tournamentID", "challongeID"})
 * }, indexes={
 *     @ORM\Index(name="ctParticipant_team", columns={"teamID"}),
 *     @ORM\Index(name="ctParticipant_user", columns={"userID"})
 * })
 */
class Participant
{
    const MISC_TEAM = 'team:';
    const MISC_USER = 'user:';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer", options={"unsigned": true})
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="ChallongeTournaments\Entity\Tournament", inversedBy="participants")
     * @ORM\JoinColumn(name="tournamentID", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected $tournament;

    /**
     * @ORM\Column(type="string", length=32)
     */
    protected $challongeID = '';

    /**
     * @ORM\Column(type="string", length=190)
     */
    protected $name = '';

    /**
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $seed = 0;

    /**
     * @ORM\Column(type="integer", options={"unsigned": true}, nullable=true)
     */
    protected $finalRank;

    /**
     * @ORM\Column(type="boolean")
     */
    protected $active = true;

    /**
     * The free-text field on Challonge; the seeder writes the team/user link into it.
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    protected $misc;

    /**
     * Participant IDs within the group stage of a two-stage tournament (the group stage matches refer to these).
     *
     * @ORM\Column(type="json", nullable=true)
     */
    protected $groupPlayerIDs;

    /**
     * tmTeam.id of the team this participant stands for.
     *
     * @ORM\Column(type="integer", options={"unsigned": true}, nullable=true)
     */
    protected $teamID;

    /**
     * User ID of the single player this participant stands for.
     *
     * @ORM\Column(type="integer", options={"unsigned": true}, nullable=true)
     */
    protected $userID;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    protected $attributes;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $updatedAt;

    public function __construct(Tournament $tournament, string $challongeID)
    {
        $this->tournament = $tournament;
        $this->challongeID = $challongeID;
        $this->updatedAt = new DateTime();
    }

    public function getID(): int
    {
        return (int) $this->id;
    }

    public function getTournament(): Tournament
    {
        return $this->tournament;
    }

    public function getChallongeID(): string
    {
        return (string) $this->challongeID;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = mb_substr($name, 0, 190);
    }

    public function getSeed(): int
    {
        return (int) $this->seed;
    }

    public function setSeed(int $seed): void
    {
        $this->seed = max(0, $seed);
    }

    public function getFinalRank(): ?int
    {
        return $this->finalRank !== null ? (int) $this->finalRank : null;
    }

    public function setFinalRank(?int $rank): void
    {
        $this->finalRank = $rank;
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getMisc(): ?string
    {
        return $this->misc;
    }

    public function setMisc(?string $misc): void
    {
        $this->misc = $misc !== null ? mb_substr($misc, 0, 255) : null;
        // the misc field is the durable link to the team / user
        if ($misc !== null && preg_match('/^' . self::MISC_TEAM . '(\d+)$/', trim($misc), $m)) {
            $this->teamID = (int) $m[1];
            $this->userID = null;
        } elseif ($misc !== null && preg_match('/^' . self::MISC_USER . '(\d+)$/', trim($misc), $m)) {
            $this->userID = (int) $m[1];
            $this->teamID = null;
        }
    }

    /**
     * @return string[]
     */
    public function getGroupPlayerIDs(): array
    {
        return is_array($this->groupPlayerIDs) ? array_map('strval', $this->groupPlayerIDs) : [];
    }

    public function setGroupPlayerIDs(array $ids): void
    {
        $this->groupPlayerIDs = array_values(array_map('strval', $ids));
    }

    /**
     * Whether a match's player ID (final stage or group stage) refers to this participant.
     */
    public function matchesPlayerID(?string $playerID): bool
    {
        if ($playerID === null || $playerID === '') {
            return false;
        }

        return $this->getChallongeID() === (string) $playerID || in_array((string) $playerID, $this->getGroupPlayerIDs(), true);
    }

    public function getTeamID(): ?int
    {
        return $this->teamID !== null ? (int) $this->teamID : null;
    }

    public function setTeamID(?int $teamID): void
    {
        $this->teamID = $teamID ?: null;
    }

    public function getUserID(): ?int
    {
        return $this->userID !== null ? (int) $this->userID : null;
    }

    public function setUserID(?int $userID): void
    {
        $this->userID = $userID ?: null;
    }

    public function getAttributes(): array
    {
        return is_array($this->attributes) ? $this->attributes : [];
    }

    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function getUpdatedAt(): DateTime
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new DateTime();
    }
}
