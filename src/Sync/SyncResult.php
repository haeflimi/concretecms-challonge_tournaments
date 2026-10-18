<?php

namespace ChallongeTournaments\Sync;

use ChallongeTournaments\Entity\Tournament;

/**
 * What a sync found: whether anything changed and what (see TournamentSync::diff()).
 */
class SyncResult
{
    /** @var Tournament */
    protected $tournament;

    /** @var bool */
    protected $changed;

    /** @var array */
    protected $diff;

    /** @var string|null */
    protected $error;

    public function __construct(Tournament $tournament, bool $changed, array $diff, ?string $error)
    {
        $this->tournament = $tournament;
        $this->changed = $changed;
        $this->diff = $diff;
        $this->error = $error;
    }

    public function getTournament(): Tournament
    {
        return $this->tournament;
    }

    public function hasChanged(): bool
    {
        return $this->changed;
    }

    public function getDiff(): array
    {
        return $this->diff;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    /**
     * Challonge match IDs whose state or score changed.
     *
     * @return string[]
     */
    public function getChangedMatchIDs(): array
    {
        return array_map('strval', array_keys($this->diff['matches'] ?? []));
    }

    /**
     * @return array{0: string, 1: string}|null old and new tournament state
     */
    public function getStateChange(): ?array
    {
        return $this->diff['state'] ?? null;
    }

    public function summary(): string
    {
        if ($this->error !== null) {
            return t('Sync failed: %s', $this->error);
        }
        if (!$this->changed) {
            return t('No changes.');
        }
        $parts = [];
        if ($this->getStateChange() !== null) {
            $parts[] = t('state %s → %s', $this->getStateChange()[0], $this->getStateChange()[1]);
        }
        $n = count($this->getChangedMatchIDs());
        if ($n > 0) {
            $parts[] = t2('%d match changed', '%d matches changed', $n);
        }
        if (!empty($this->diff['participants'])) {
            $parts[] = t('participants changed');
        }

        return $parts !== [] ? implode(', ', $parts) : t('Changed.');
    }
}
