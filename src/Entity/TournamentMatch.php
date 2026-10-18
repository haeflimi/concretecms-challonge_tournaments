<?php

namespace ChallongeTournaments\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * A match of a tournament as Challonge knows it. Player IDs are Challonge participant IDs (in the group stage of a
 * two-stage tournament the group player IDs, see Participant::matchesPlayerID()).
 *
 * @ORM\Entity()
 * @ORM\Table(name="ctMatch", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="ctMatch_challonge", columns={"tournamentID", "challongeID"})
 * }, indexes={
 *     @ORM\Index(name="ctMatch_state", columns={"tournamentID", "state"})
 * })
 */
class TournamentMatch
{
    const STATE_PENDING = 'pending';
    const STATE_OPEN = 'open';
    const STATE_COMPLETE = 'complete';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer", options={"unsigned": true})
     * @ORM\GeneratedValue
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="ChallongeTournaments\Entity\Tournament", inversedBy="matches")
     * @ORM\JoinColumn(name="tournamentID", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected $tournament;

    /**
     * @ORM\Column(type="string", length=32)
     */
    protected $challongeID = '';

    /**
     * @ORM\Column(type="string", length=16)
     */
    protected $state = self::STATE_PENDING;

    /**
     * Negative rounds are the losers bracket of a double elimination.
     *
     * @ORM\Column(type="integer")
     */
    protected $round = 0;

    /**
     * The letter(s) Challonge labels the match with (A, B, ..., AA).
     *
     * @ORM\Column(type="string", length=16, nullable=true)
     */
    protected $identifier;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $suggestedPlayOrder;

    /**
     * Group of a two-stage tournament's group stage, null in the final stage.
     *
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    protected $groupID;

    /**
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    protected $player1ChallongeID;

    /**
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    protected $player2ChallongeID;

    /**
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    protected $winnerChallongeID;

    /**
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    protected $loserChallongeID;

    /**
     * Sets as Challonge formats them: "3-1,2-3,3-2" (player 1 score first).
     *
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    protected $scoresCsv;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $underwayAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $scheduledTime;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $completedAt;

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

    public function getState(): string
    {
        return (string) $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = mb_substr($state, 0, 16);
    }

    public function isOpen(): bool
    {
        return $this->getState() === self::STATE_OPEN;
    }

    public function isComplete(): bool
    {
        return $this->getState() === self::STATE_COMPLETE;
    }

    public function isUnderway(): bool
    {
        return $this->isOpen() && $this->underwayAt !== null;
    }

    public function getRound(): int
    {
        return (int) $this->round;
    }

    public function setRound(int $round): void
    {
        $this->round = $round;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(?string $identifier): void
    {
        $this->identifier = $identifier !== null ? mb_substr($identifier, 0, 16) : null;
    }

    public function getSuggestedPlayOrder(): ?int
    {
        return $this->suggestedPlayOrder !== null ? (int) $this->suggestedPlayOrder : null;
    }

    public function setSuggestedPlayOrder(?int $order): void
    {
        $this->suggestedPlayOrder = $order;
    }

    public function getGroupID(): ?string
    {
        return $this->groupID;
    }

    public function setGroupID(?string $groupID): void
    {
        $this->groupID = $groupID !== null && $groupID !== '' ? mb_substr($groupID, 0, 32) : null;
    }

    public function isGroupStage(): bool
    {
        return $this->groupID !== null;
    }

    public function getPlayer1ChallongeID(): ?string
    {
        return $this->player1ChallongeID;
    }

    public function setPlayer1ChallongeID(?string $id): void
    {
        $this->player1ChallongeID = $id !== null && $id !== '' ? (string) $id : null;
    }

    public function getPlayer2ChallongeID(): ?string
    {
        return $this->player2ChallongeID;
    }

    public function setPlayer2ChallongeID(?string $id): void
    {
        $this->player2ChallongeID = $id !== null && $id !== '' ? (string) $id : null;
    }

    public function getWinnerChallongeID(): ?string
    {
        return $this->winnerChallongeID;
    }

    public function setWinnerChallongeID(?string $id): void
    {
        $this->winnerChallongeID = $id !== null && $id !== '' ? (string) $id : null;
    }

    public function getLoserChallongeID(): ?string
    {
        return $this->loserChallongeID;
    }

    public function setLoserChallongeID(?string $id): void
    {
        $this->loserChallongeID = $id !== null && $id !== '' ? (string) $id : null;
    }

    public function getScoresCsv(): ?string
    {
        return $this->scoresCsv;
    }

    public function setScoresCsv(?string $scores): void
    {
        $this->scoresCsv = $scores !== null && $scores !== '' ? mb_substr($scores, 0, 100) : null;
    }

    public function getUnderwayAt(): ?DateTime
    {
        return $this->underwayAt;
    }

    public function setUnderwayAt(?DateTime $at): void
    {
        $this->underwayAt = $at;
    }

    public function getScheduledTime(): ?DateTime
    {
        return $this->scheduledTime;
    }

    public function setScheduledTime(?DateTime $at): void
    {
        $this->scheduledTime = $at;
    }

    public function getCompletedAt(): ?DateTime
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?DateTime $at): void
    {
        $this->completedAt = $at;
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

    /**
     * The participants of both sides (null for a side not yet decided).
     */
    public function getPlayer1(): ?Participant
    {
        return $this->findParticipant($this->player1ChallongeID);
    }

    public function getPlayer2(): ?Participant
    {
        return $this->findParticipant($this->player2ChallongeID);
    }

    public function getWinner(): ?Participant
    {
        return $this->findParticipant($this->winnerChallongeID);
    }

    public function getLoser(): ?Participant
    {
        return $this->findParticipant($this->loserChallongeID);
    }

    /**
     * Which side a participant plays on: 1, 2 or null.
     */
    public function getSideOf(Participant $participant): ?int
    {
        if ($participant->matchesPlayerID($this->player1ChallongeID)) {
            return 1;
        }
        if ($participant->matchesPlayerID($this->player2ChallongeID)) {
            return 2;
        }

        return null;
    }

    /**
     * Both sides' totals of won sets, from the scores csv: [player1 sets, player2 sets].
     *
     * @return array{0: int, 1: int}|null
     */
    public function getSetWins(): ?array
    {
        $sets = self::parseScores($this->scoresCsv);
        if ($sets === []) {
            return null;
        }
        $wins = [0, 0];
        foreach ($sets as [$a, $b]) {
            if ($a > $b) {
                $wins[0]++;
            } elseif ($b > $a) {
                $wins[1]++;
            }
        }

        return $wins;
    }

    /**
     * "3-1,2-3" => [[3, 1], [2, 3]]. Negative scores are allowed by Challonge ("-1--2") and parsed as such.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function parseScores(?string $csv): array
    {
        $sets = [];
        foreach (explode(',', (string) $csv) as $set) {
            $set = trim($set);
            if ($set === '') {
                continue;
            }
            if (preg_match('/^(-?\d+)-(-?\d+)$/', $set, $m)) {
                $sets[] = [(int) $m[1], (int) $m[2]];
            }
        }

        return $sets;
    }

    protected function findParticipant(?string $playerID): ?Participant
    {
        if ($playerID === null || $playerID === '') {
            return null;
        }
        foreach ($this->tournament->getParticipants() as $participant) {
            if ($participant->matchesPlayerID($playerID)) {
                return $participant;
            }
        }

        return null;
    }
}
