<?php

namespace ChallongeTournaments\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tournament on challonge.com that is shown on the site. Challonge owns the tournament; this row links it to an
 * event and a team pool and caches what the API returned at the last sync (the raw attributes in `attributes`).
 *
 * @ORM\Entity()
 * @ORM\Table(name="ctTournament", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="ctTournament_challonge", columns={"challongeID"})
 * }, indexes={
 *     @ORM\Index(name="ctTournament_event", columns={"eventID"}),
 *     @ORM\Index(name="ctTournament_state", columns={"state"})
 * })
 */
class Tournament
{
    const STATE_PENDING = 'pending';
    const STATE_CHECKING_IN = 'checking_in';
    const STATE_CHECKED_IN = 'checked_in';
    const STATE_GROUP_STAGES_UNDERWAY = 'group_stages_underway';
    const STATE_GROUP_STAGES_FINALIZED = 'group_stages_finalized';
    const STATE_UNDERWAY = 'underway';
    const STATE_AWAITING_REVIEW = 'awaiting_review';
    const STATE_COMPLETE = 'complete';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer", options={"unsigned": true})
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * The numeric Challonge ID, kept as a string (the API returns it as one).
     *
     * @ORM\Column(type="string", length=32)
     */
    protected $challongeID = '';

    /**
     * The URL part of the tournament (challonge.com/<slug>).
     *
     * @ORM\Column(type="string", length=64)
     */
    protected $slug = '';

    /**
     * The community subdomain the tournament lives in, null for the account's own tournaments.
     *
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    protected $community;

    /**
     * @ORM\Column(type="string", length=190)
     */
    protected $name = '';

    /**
     * @ORM\Column(type="string", length=190, nullable=true)
     */
    protected $gameName;

    /**
     * single elimination, double elimination, round robin, swiss, free for all
     *
     * @ORM\Column(type="string", length=32)
     */
    protected $tournamentType = '';

    /**
     * @ORM\Column(type="string", length=32)
     */
    protected $state = self::STATE_PENDING;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $startsAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $startedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $completedAt;

    /**
     * @ORM\Column(type="string", length=255)
     */
    protected $fullURL = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    protected $liveImageURL;

    /**
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $participantsCount = 0;

    /**
     * @ORM\Column(type="boolean")
     */
    protected $groupStagesEnabled = false;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    protected $description;

    /**
     * ID of the Community Event Manager event (cemEvents.id) the tournament belongs to.
     *
     * @ORM\Column(type="integer", options={"unsigned": true}, nullable=true)
     */
    protected $eventID;

    /**
     * ID of the Team Manager pool (tmTeamPool.gID, a group ID) the participants are seeded from.
     *
     * @ORM\Column(type="integer", options={"unsigned": true}, nullable=true)
     */
    protected $poolID;

    /**
     * Order within an event.
     *
     * @ORM\Column(type="integer")
     */
    protected $position = 0;

    /**
     * Hidden tournaments are kept in sync but not shown by the blocks.
     *
     * @ORM\Column(type="boolean")
     */
    protected $visible = true;

    /**
     * Whether captains may report results of this tournament on the site.
     *
     * @ORM\Column(type="boolean")
     */
    protected $reportingEnabled = true;

    /**
     * Group whose members moderate this tournament (null: only the global moderators and administrators).
     *
     * @ORM\Column(type="integer", options={"unsigned": true}, nullable=true)
     */
    protected $moderatorGroupID;

    /**
     * The tournament attributes as the API returned them at the last sync.
     *
     * @ORM\Column(type="json", nullable=true)
     */
    protected $attributes;

    /**
     * Hash over the synced tournament, participants and matches: changes when anything relevant changed.
     *
     * @ORM\Column(type="string", length=40, nullable=true)
     */
    protected $syncHash;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $lastSyncedAt;

    /**
     * When the synced data last differed from the sync before.
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $lastChangedAt;

    /**
     * The error of the last sync, null if it succeeded.
     *
     * @ORM\Column(type="text", nullable=true)
     */
    protected $lastSyncError;

    /**
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $createdBy = 0;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $createdAt;

    /**
     * @ORM\OneToMany(targetEntity="ChallongeTournaments\Entity\Participant", mappedBy="tournament", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"seed" = "ASC"})
     */
    protected $participants;

    /**
     * @ORM\OneToMany(targetEntity="ChallongeTournaments\Entity\TournamentMatch", mappedBy="tournament", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"suggestedPlayOrder" = "ASC"})
     */
    protected $matches;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->participants = new ArrayCollection();
        $this->matches = new ArrayCollection();
    }

    public function getID(): int
    {
        return (int) $this->id;
    }

    public function getChallongeID(): string
    {
        return (string) $this->challongeID;
    }

    public function setChallongeID(string $id): void
    {
        $this->challongeID = $id;
    }

    public function getSlug(): string
    {
        return (string) $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getCommunity(): ?string
    {
        return $this->community !== null && $this->community !== '' ? $this->community : null;
    }

    public function setCommunity(?string $community): void
    {
        $this->community = $community !== null && trim($community) !== '' ? trim($community) : null;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = mb_substr($name, 0, 190);
    }

    public function getGameName(): ?string
    {
        return $this->gameName;
    }

    public function setGameName(?string $gameName): void
    {
        $this->gameName = $gameName !== null ? mb_substr($gameName, 0, 190) : null;
    }

    public function getTournamentType(): string
    {
        return (string) $this->tournamentType;
    }

    public function setTournamentType(string $type): void
    {
        $this->tournamentType = mb_substr($type, 0, 32);
    }

    public function getState(): string
    {
        return (string) $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = mb_substr($state, 0, 32);
    }

    public function isPending(): bool
    {
        return in_array($this->getState(), [self::STATE_PENDING, self::STATE_CHECKING_IN, self::STATE_CHECKED_IN], true);
    }

    public function isUnderway(): bool
    {
        return in_array($this->getState(), [self::STATE_UNDERWAY, self::STATE_GROUP_STAGES_UNDERWAY, self::STATE_GROUP_STAGES_FINALIZED, self::STATE_AWAITING_REVIEW], true);
    }

    public function isComplete(): bool
    {
        return $this->getState() === self::STATE_COMPLETE;
    }

    /**
     * Whether a sync may still change anything: everything but complete tournaments that were synced after completion.
     */
    public function isActive(): bool
    {
        if (!$this->isComplete()) {
            return true;
        }

        return $this->completedAt === null || $this->lastSyncedAt === null || $this->lastSyncedAt < $this->completedAt;
    }

    public function getStartsAt(): ?DateTime
    {
        return $this->startsAt;
    }

    public function setStartsAt(?DateTime $startsAt): void
    {
        $this->startsAt = $startsAt;
    }

    public function getStartedAt(): ?DateTime
    {
        return $this->startedAt;
    }

    public function setStartedAt(?DateTime $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getCompletedAt(): ?DateTime
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?DateTime $completedAt): void
    {
        $this->completedAt = $completedAt;
    }

    public function getFullURL(): string
    {
        return (string) $this->fullURL;
    }

    public function setFullURL(string $url): void
    {
        $this->fullURL = mb_substr($url, 0, 255);
    }

    public function getLiveImageURL(): ?string
    {
        return $this->liveImageURL;
    }

    public function setLiveImageURL(?string $url): void
    {
        $this->liveImageURL = $url !== null ? mb_substr($url, 0, 255) : null;
    }

    public function getParticipantsCount(): int
    {
        return (int) $this->participantsCount;
    }

    public function setParticipantsCount(int $count): void
    {
        $this->participantsCount = max(0, $count);
    }

    public function isGroupStagesEnabled(): bool
    {
        return (bool) $this->groupStagesEnabled;
    }

    public function setGroupStagesEnabled(bool $enabled): void
    {
        $this->groupStagesEnabled = $enabled;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getEventID(): ?int
    {
        return $this->eventID !== null ? (int) $this->eventID : null;
    }

    public function setEventID(?int $eventID): void
    {
        $this->eventID = $eventID ?: null;
    }

    public function getPoolID(): ?int
    {
        return $this->poolID !== null ? (int) $this->poolID : null;
    }

    public function setPoolID(?int $poolID): void
    {
        $this->poolID = $poolID ?: null;
    }

    public function getPosition(): int
    {
        return (int) $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isVisible(): bool
    {
        return (bool) $this->visible;
    }

    public function setVisible(bool $visible): void
    {
        $this->visible = $visible;
    }

    public function isReportingEnabled(): bool
    {
        return (bool) $this->reportingEnabled;
    }

    public function setReportingEnabled(bool $enabled): void
    {
        $this->reportingEnabled = $enabled;
    }

    public function getModeratorGroupID(): ?int
    {
        return $this->moderatorGroupID !== null ? (int) $this->moderatorGroupID : null;
    }

    public function setModeratorGroupID(?int $gID): void
    {
        $this->moderatorGroupID = $gID ?: null;
    }

    public function getAttributes(): array
    {
        return is_array($this->attributes) ? $this->attributes : [];
    }

    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function getSyncHash(): ?string
    {
        return $this->syncHash;
    }

    public function setSyncHash(?string $hash): void
    {
        $this->syncHash = $hash;
    }

    public function getLastSyncedAt(): ?DateTime
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?DateTime $at): void
    {
        $this->lastSyncedAt = $at;
    }

    public function getLastChangedAt(): ?DateTime
    {
        return $this->lastChangedAt;
    }

    public function setLastChangedAt(?DateTime $at): void
    {
        $this->lastChangedAt = $at;
    }

    public function getLastSyncError(): ?string
    {
        return $this->lastSyncError;
    }

    public function setLastSyncError(?string $error): void
    {
        $this->lastSyncError = $error;
    }

    public function getCreatedBy(): int
    {
        return (int) $this->createdBy;
    }

    public function setCreatedBy(int $uID): void
    {
        $this->createdBy = $uID;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    /**
     * @return Collection|Participant[]
     */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    /**
     * @return Collection|TournamentMatch[]
     */
    public function getMatches(): Collection
    {
        return $this->matches;
    }

    public function getParticipantByChallongeID(?string $challongeID): ?Participant
    {
        if ($challongeID === null || $challongeID === '') {
            return null;
        }
        foreach ($this->participants as $participant) {
            if ($participant->getChallongeID() === (string) $challongeID) {
                return $participant;
            }
        }

        return null;
    }

    /**
     * The public page of the tournament on Challonge.
     */
    public function getChallongeURL(): string
    {
        if ($this->getFullURL() !== '') {
            return $this->getFullURL();
        }
        $host = $this->getCommunity() ? $this->getCommunity() . '.challonge.com' : 'challonge.com';

        return 'https://' . $host . '/' . $this->getSlug();
    }

    /**
     * The embeddable bracket module of the tournament.
     *
     * @param array<string, string|int|float> $params query parameters of the module (theme, multiplier, ...)
     */
    public function getEmbedURL(array $params = []): string
    {
        $host = $this->getCommunity() ? $this->getCommunity() . '.challonge.com' : 'challonge.com';
        $query = http_build_query(array_filter($params, static function ($value) {
            return $value !== null && $value !== '';
        }));

        return 'https://' . $host . '/' . $this->getSlug() . '/module' . ($query !== '' ? '?' . $query : '');
    }
}
