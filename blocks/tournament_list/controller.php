<?php

namespace Concrete\Package\ChallongeTournaments\Block\TournamentList;

use ChallongeTournaments\Block\AbstractTournamentBlockController;
use Concrete\Core\Page\Page;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * The tournaments of an event (or all) as cards: game, type, state, participants, with a link to a detail page
 * (a page with the Tournament Overview block, which picks the tournament from ?tournament=) or to Challonge.
 */
class Controller extends AbstractTournamentBlockController
{
    protected $btTable = 'btChallongeTournamentList';
    protected $btInterfaceWidth = 500;
    protected $btInterfaceHeight = 420;
    protected $btDefaultSet = 'social';

    /** @var int */
    public $detailPageID = 0;

    /** @var string */
    public $title = '';

    public function getBlockTypeName()
    {
        return t('Tournament List');
    }

    public function getBlockTypeDescription()
    {
        return t('The Challonge tournaments of the event: game, state and participants, linking to the tournament page.');
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
        $args['showCompleted'] = empty($args['showCompleted']) ? 0 : 1;
        $args['detailPageID'] = (int) ($args['detailPageID'] ?? 0);
        $args['title'] = mb_substr(trim((string) ($args['title'] ?? '')), 0, 190);
        parent::save($args);
    }

    public function view()
    {
        $tournaments = $this->getTournaments();
        $this->setCommonViewData($tournaments);
        $detailURL = null;
        if ($this->detailPageID) {
            $page = Page::getByID((int) $this->detailPageID);
            if ($page && !$page->isError()) {
                $detailURL = (string) \Concrete\Core\Support\Facade\Url::to($page);
            }
        }
        $this->set('detailURL', $detailURL);
        $this->set('title', $this->title);
    }
}
