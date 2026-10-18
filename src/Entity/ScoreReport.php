<?php

namespace ChallongeTournaments\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * A result a captain (or moderator) reported on the site. Pending until the opposing captain or a moderator
 * confirms it; confirmed reports are written to Challonge (status applied), a failed write keeps the error.
 *
 * @ORM\Entity()
 * @ORM\Table(name="ctScoreReport", indexes={
 *     @ORM\Index(name="ctScoreReport_match", columns={"matchID", "status"}),
 *     @ORM\Index(name="ctScoreReport_tournament", columns={"tournamentID", "status"})
 * })
 */
class ScoreReport
{
    const STATUS_PENDING = 'pending';
    const STATUS_APPLIED = 'applied';
    const STATUS_REJECTED = 'rejected';
    const STATUS_WITHDRAWN = 'withdrawn';
    const STATUS_FAILED = 'failed';
    const STATUS_SUPERSEDED = 'superseded';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer", options={"unsigned": true})
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="ChallongeTournaments\Entity\Tournament")
     * @ORM\JoinColumn(name="tournamentID", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected $tournament;

    /**
     * @ORM\ManyToOne(targetEntity="ChallongeTournaments\Entity\TournamentMatch")
     * @ORM\JoinColumn(name="matchID", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected $match;

    /**
     * @ORM\Column(type="integer", options={"unsigned": true})
     */
    protected $reportedBy = 0;

    /**
     * Side the reporter plays on (1 or 2), null for a moderator's report.
     *
     * @ORM\Column(type="smallint", nullable=true)
     */
    protected $reporterSide;

    /**
     * @ORM\Column(type="string", length=32)
     */
    protected $winnerChallongeID = '';

    /**
     * @ORM\Column(type="string", length=100)
     */
    protected $scoresCsv = '';

    /**
     * @ORM\Column(type="string", length=16)
     */
    protected $status = self::STATUS_PENDING;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    protected $note;

    /**
     * @ORM\Column(type="integer", options={"unsigned": true}, nullable=true)
     */
    protected $resolvedBy;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $resolvedAt;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    protected $error;

    /**
     * @ORM\Column(type="datetime")
     */
    protected $createdAt;

    public function __construct(TournamentMatch $match, int $reportedBy)
    {
        $this->match = $match;
        $this->tournament = $match->getTournament();
        $this->reportedBy = $reportedBy;
        $this->createdAt = new DateTime();
    }

    public function getID(): int
    {
        return (int) $this->id;
    }

    public function getTournament(): Tournament
    {
        return $this->tournament;
    }

    public function getMatch(): TournamentMatch
    {
        return $this->match;
    }

    public function getReportedBy(): int
    {
        return (int) $this->reportedBy;
    }

    public function getReporterSide(): ?int
    {
        return $this->reporterSide !== null ? (int) $this->reporterSide : null;
    }

    public function setReporterSide(?int $side): void
    {
        $this->reporterSide = $side;
    }

    public function getWinnerChallongeID(): string
    {
        return (string) $this->winnerChallongeID;
    }

    public function setWinnerChallongeID(string $id): void
    {
        $this->winnerChallongeID = $id;
    }

    public function getScoresCsv(): string
    {
        return (string) $this->scoresCsv;
    }

    public function setScoresCsv(string $scores): void
    {
        $this->scoresCsv = mb_substr($scores, 0, 100);
    }

    public function getStatus(): string
    {
        return (string) $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isPending(): bool
    {
        return $this->getStatus() === self::STATUS_PENDING;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;
    }

    public function getResolvedBy(): ?int
    {
        return $this->resolvedBy !== null ? (int) $this->resolvedBy : null;
    }

    public function getResolvedAt(): ?DateTime
    {
        return $this->resolvedAt;
    }

    public function resolve(string $status, ?int $uID, ?string $error = null): void
    {
        $this->status = $status;
        $this->resolvedBy = $uID;
        $this->resolvedAt = new DateTime();
        $this->error = $error;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getWinner(): ?Participant
    {
        return $this->tournament->getParticipantByChallongeID($this->winnerChallongeID)
            ?? $this->findByPlayerID($this->winnerChallongeID);
    }

    protected function findByPlayerID(string $playerID): ?Participant
    {
        foreach ($this->tournament->getParticipants() as $participant) {
            if ($participant->matchesPlayerID($playerID)) {
                return $participant;
            }
        }

        return null;
    }
}
