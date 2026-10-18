<?php

namespace ChallongeTournaments\Api;

/**
 * Values of "Change Tournament State" (PUT .../tournaments/{id}/change_state.json). An illegal transition is a 422.
 */
final class TournamentState
{
    const PROCESS_CHECKIN = 'process_checkin';
    const ABORT_CHECKIN = 'abort_checkin';
    const START_GROUP_STAGE = 'start_group_stage';
    const FINALIZE_GROUP_STAGE = 'finalize_group_stage';
    const RESET_GROUP_STAGE = 'reset_group_stage';
    const START = 'start';
    const FINALIZE = 'finalize';
    const RESET = 'reset';
    const OPEN_PREDICTIONS = 'open_predictions';

    const ALL = [
        self::PROCESS_CHECKIN, self::ABORT_CHECKIN, self::START_GROUP_STAGE, self::FINALIZE_GROUP_STAGE,
        self::RESET_GROUP_STAGE, self::START, self::FINALIZE, self::RESET, self::OPEN_PREDICTIONS,
    ];

    /**
     * The transitions the dashboard offers for a tournament state, with their labels.
     *
     * @return array<string, string> transition => label
     */
    public static function transitionsFor(string $state, bool $groupStages): array
    {
        switch ($state) {
            case 'pending':
                return $groupStages
                    ? [self::START_GROUP_STAGE => t('Start group stage')]
                    : [self::START => t('Start tournament')];
            case 'checking_in':
                return [self::PROCESS_CHECKIN => t('Process check-ins'), self::ABORT_CHECKIN => t('Abort check-in')];
            case 'checked_in':
                return $groupStages
                    ? [self::START_GROUP_STAGE => t('Start group stage')]
                    : [self::START => t('Start tournament')];
            case 'group_stages_underway':
                return [self::FINALIZE_GROUP_STAGE => t('Finalize group stage'), self::RESET_GROUP_STAGE => t('Reset group stage')];
            case 'group_stages_finalized':
                return [self::START => t('Start final stage'), self::RESET_GROUP_STAGE => t('Reset group stage')];
            case 'underway':
                return [self::RESET => t('Reset tournament')];
            case 'awaiting_review':
                return [self::FINALIZE => t('Finalize tournament'), self::RESET => t('Reset tournament')];
            case 'complete':
                return [self::RESET => t('Reset tournament')];
        }

        return [];
    }
}
