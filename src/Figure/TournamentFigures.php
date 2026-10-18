<?php

namespace ChallongeTournaments\Figure;

/**
 * Figures for the block "Event Figure" (community_event_manager), registered on on_event_figures_register.
 * Everything is derived from the synced tables; the registry is only touched if the event manager is installed.
 */
class TournamentFigures
{
    /**
     * @param \CommunityEventManager\Figure\FigureRegistry $registry
     */
    public static function register($registry): void
    {
        if (!class_exists(\CommunityEventManager\Figure\CallbackFigure::class)) {
            return;
        }
        $group = t('Tournaments');
        $eventFilter = static function (\CommunityEventManager\Figure\FigureContext $c, array $o): array {
            $event = $c->event($o);

            return $event ? [' AND t.eventID = ?', [$event->getID()]] : ['', []];
        };

        $registry->register(\CommunityEventManager\Figure\CallbackFigure::make('tournaments.count', t('Tournaments'), $group, 'fas fa-trophy', ['event'], static function ($c, array $o) use ($eventFilter) {
            [$where, $params] = $eventFilter($c, $o);

            return \CommunityEventManager\Figure\FigureValue::number($c->db->fetchOne('SELECT COUNT(*) FROM ctTournament t WHERE t.visible = 1' . $where, $params));
        }));

        $registry->register(\CommunityEventManager\Figure\CallbackFigure::make('tournaments.participants', t('Tournament participants'), $group, 'fas fa-users', ['event'], static function ($c, array $o) use ($eventFilter) {
            [$where, $params] = $eventFilter($c, $o);

            return \CommunityEventManager\Figure\FigureValue::number($c->db->fetchOne('SELECT COUNT(*) FROM ctParticipant p INNER JOIN ctTournament t ON t.id = p.tournamentID WHERE t.visible = 1' . $where, $params));
        }));

        $registry->register(\CommunityEventManager\Figure\CallbackFigure::make('tournaments.matches_played', t('Matches played'), $group, 'fas fa-gamepad', ['event'], static function ($c, array $o) use ($eventFilter) {
            [$where, $params] = $eventFilter($c, $o);

            return \CommunityEventManager\Figure\FigureValue::number($c->db->fetchOne('SELECT COUNT(*) FROM ctMatch m INNER JOIN ctTournament t ON t.id = m.tournamentID WHERE t.visible = 1 AND m.state = \'complete\'' . $where, $params));
        }));

        $registry->register(\CommunityEventManager\Figure\CallbackFigure::make('tournaments.live', t('Matches live now'), $group, 'fas fa-broadcast-tower', ['event'], static function ($c, array $o) use ($eventFilter) {
            [$where, $params] = $eventFilter($c, $o);

            return \CommunityEventManager\Figure\FigureValue::number($c->db->fetchOne('SELECT COUNT(*) FROM ctMatch m INNER JOIN ctTournament t ON t.id = m.tournamentID WHERE t.visible = 1 AND m.state = \'open\' AND m.underwayAt IS NOT NULL' . $where, $params));
        }));

        $registry->register(\CommunityEventManager\Figure\CallbackFigure::make('tournaments.champion', t('Tournament winner'), $group, 'fas fa-crown', ['event'], static function ($c, array $o) use ($eventFilter) {
            [$where, $params] = $eventFilter($c, $o);
            $name = $c->db->fetchOne('SELECT p.name FROM ctParticipant p INNER JOIN ctTournament t ON t.id = p.tournamentID WHERE t.visible = 1 AND t.state = \'complete\' AND p.finalRank = 1' . $where . ' ORDER BY t.completedAt DESC LIMIT 1', $params);

            return \CommunityEventManager\Figure\FigureValue::text($name !== false && $name !== null ? (string) $name : null);
        }));
    }
}
