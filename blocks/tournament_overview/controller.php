<?php

namespace Concrete\Package\ChallongeTournaments\Block\TournamentOverview;

use ChallongeTournaments\Block\AbstractTournamentBlockController;
use ChallongeTournaments\Tournament\TournamentRepository;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * One tournament in full: participants, live / upcoming / finished matches with result reporting, standings and the
 * embedded Challonge bracket. With several tournaments (an event's) they are shown as tabs. ?tournament=<id> picks
 * one (from the Tournament List block).
 */
class Controller extends AbstractTournamentBlockController
{
    protected $btTable = 'btChallongeTournamentOverview';
    protected $btInterfaceWidth = 520;
    protected $btInterfaceHeight = 560;
    protected $btDefaultSet = 'social';

    /** @var int */
    public $showParticipants = 1;

    /** @var int */
    public $showMatches = 1;

    /** @var int */
    public $showStandings = 1;

    /** @var int */
    public $showBracket = 1;

    /** @var int */
    public $bracketHeight = 500;

    /** @var int seconds between two state polls on the page, 0 = off */
    public $refreshSeconds = 60;

    public function getBlockTypeName()
    {
        return t('Tournament Overview');
    }

    public function getBlockTypeDescription()
    {
        return t('A Challonge tournament in full: participants, matches with result reporting, standings and the bracket.');
    }

    public function add()
    {
        $this->edit();
    }

    public function edit()
    {
        $this->set('modeChoices', $this->getModeChoices());
        $this->set('tournamentChoices', $this->getTournamentChoices());
        $this->set('selectedIDs', $this->getSpecifiedIDs());
    }

    public function save($args)
    {
        $args['mode'] = in_array($args['mode'] ?? '', [self::MODE_EVENT, self::MODE_ALL, self::MODE_SPECIFY], true) ? $args['mode'] : self::MODE_EVENT;
        $args['tournamentIDs'] = $this->cleanTournamentIDs($args['tournamentIDs'] ?? []);
        foreach (['showCompleted', 'showParticipants', 'showMatches', 'showStandings', 'showBracket'] as $flag) {
            $args[$flag] = empty($args[$flag]) ? 0 : 1;
        }
        $args['bracketHeight'] = max(200, min(2000, (int) ($args['bracketHeight'] ?? 500)));
        $args['refreshSeconds'] = max(0, min(3600, (int) ($args['refreshSeconds'] ?? 60)));
        parent::save($args);
    }

    public function view()
    {
        $tournaments = $this->getTournaments();
        // a tournament picked by the list block (any visible one, so the list may link across events)
        $picked = (int) $this->request->query->get('tournament', 0);
        if ($picked > 0) {
            $tournament = $this->app->make(TournamentRepository::class)->getByID($picked);
            if ($tournament !== null && $tournament->isVisible()) {
                $this->refreshIfStale($tournament);
                $others = array_values(array_filter($tournaments, static function ($t) use ($picked) {
                    return $t->getID() !== $picked;
                }));
                $tournaments = array_merge([$tournament], $others);
            }
        }
        $this->setCommonViewData($tournaments);
        $this->set('options', [
            'showParticipants' => (bool) $this->showParticipants,
            'showMatches' => (bool) $this->showMatches,
            'showStandings' => (bool) $this->showStandings,
            'showBracket' => (bool) $this->showBracket,
            'bracketHeight' => (int) $this->bracketHeight,
            'refreshSeconds' => (int) $this->refreshSeconds,
        ]);
        $this->set('bID', $this->bID);
    }
}
