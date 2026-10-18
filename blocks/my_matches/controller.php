<?php

namespace Concrete\Package\ChallongeTournaments\Block\MyMatches;

use ChallongeTournaments\Block\AbstractTournamentBlockController;
use ChallongeTournaments\Tournament\Presenter;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The signed-in user's own matches across the running tournaments (as a team member or single player), with
 * result reporting for captains. For the account page and the live dashboard.
 */
class Controller extends AbstractTournamentBlockController
{
    protected $btTable = 'btChallongeMyMatches';
    protected $btInterfaceWidth = 500;
    protected $btInterfaceHeight = 380;
    protected $btDefaultSet = 'social';

    public $mode = self::MODE_ALL;

    public $showCompleted = 0;

    /** @var int */
    public $showFinishedMatches = 1;

    /** @var string */
    public $title = '';

    public function getBlockTypeName()
    {
        return t('My Matches');
    }

    public function getBlockTypeDescription()
    {
        return t('The signed-in player\'s matches in the running Challonge tournaments, with result reporting for captains.');
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
        $args['mode'] = in_array($args['mode'] ?? '', [self::MODE_EVENT, self::MODE_ALL, self::MODE_SPECIFY], true) ? $args['mode'] : self::MODE_ALL;
        $args['tournamentIDs'] = $this->cleanTournamentIDs($args['tournamentIDs'] ?? []);
        $args['showCompleted'] = empty($args['showCompleted']) ? 0 : 1;
        $args['showFinishedMatches'] = empty($args['showFinishedMatches']) ? 0 : 1;
        $args['title'] = mb_substr(trim((string) ($args['title'] ?? '')), 0, 190);
        parent::save($args);
    }

    public function view()
    {
        $user = $this->getCurrentUser();
        $tournaments = $user ? $this->getTournaments() : [];
        $this->setCommonViewData([]);
        $entries = $user ? $this->app->make(Presenter::class)->myMatches($tournaments, $user) : [];
        if (!$this->showFinishedMatches) {
            foreach ($entries as &$entry) {
                $entry['matches'] = array_values(array_filter($entry['matches'], static function ($m) {
                    return $m['state'] !== 'complete';
                }));
            }
            unset($entry);
        }
        $this->set('entries', $entries);
        $this->set('title', $this->title);
        $this->set('bID', $this->bID);
    }
}
