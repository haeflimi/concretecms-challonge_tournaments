<?php

namespace ChallongeTournaments\Api;

/**
 * Values of "Change Match State" (PUT .../matches/{id}/change_state.json).
 */
final class MatchState
{
    const REOPEN = 'reopen';
    const MARK_AS_UNDERWAY = 'mark_as_underway';
    const UNMARK_AS_UNDERWAY = 'unmark_as_underway';

    const ALL = [self::REOPEN, self::MARK_AS_UNDERWAY, self::UNMARK_AS_UNDERWAY];
}
