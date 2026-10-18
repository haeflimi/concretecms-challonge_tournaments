<?php

namespace ChallongeTournaments\Block;

use ChallongeTournaments\Config\Settings;
use ChallongeTournaments\Controller\Api;
use ChallongeTournaments\Entity\Tournament;
use ChallongeTournaments\Sync\TournamentSync;
use ChallongeTournaments\Tournament\Presenter;
use ChallongeTournaments\Tournament\TournamentRepository;
use Concrete\Core\Block\BlockController;
use Concrete\Core\Page\Page;
use Concrete\Core\User\User;
use Concrete\Core\Validation\CSRF\Token;

/**
 * Shared by the frontend blocks: which tournaments to show (the page's event, all, or chosen ones), the presenter
 * data, the flash messages of the report actions, and the assets (unless a theme renders the blocks itself).
 */
abstract class AbstractTournamentBlockController extends BlockController
{
    const MODE_EVENT = 'event';
    const MODE_ALL = 'all';
    const MODE_SPECIFY = 'specify';

    /** Themes bind this to opt out of the built-in UI (see README, "Theme integration"). */
    const THEME_BINDING = 'challonge_tournaments.ui';

    protected $btCacheBlockRecord = true;
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockOutputOnPost = false;
    protected $btCacheBlockOutputForRegisteredUsers = false;

    /** @var string */
    public $mode = self::MODE_EVENT;

    /** @var string comma separated tournament IDs (mode specify) */
    public $tournamentIDs = '';

    /** @var int */
    public $showCompleted = 1;

    public function getBlockTypeDescription()
    {
        return t('Challonge tournaments on the site.');
    }

    /**
     * @return Tournament[]
     */
    public function getTournaments(): array
    {
        $repository = $this->app->make(TournamentRepository::class);
        switch ($this->mode) {
            case self::MODE_SPECIFY:
                $tournaments = [];
                foreach ($this->getSpecifiedIDs() as $id) {
                    $tournament = $repository->getByID($id);
                    if ($tournament !== null && $tournament->isVisible()) {
                        $tournaments[] = $tournament;
                    }
                }
                break;
            case self::MODE_ALL:
                $tournaments = $repository->getAll(true);
                break;
            default:
                $eventID = $this->getPageEventID();
                $tournaments = $eventID ? $repository->getByEvent($eventID, true) : [];
        }
        if (!$this->showCompleted) {
            $tournaments = array_values(array_filter($tournaments, static function (Tournament $t) {
                return !$t->isComplete();
            }));
        }
        foreach ($tournaments as $tournament) {
            $this->refreshIfStale($tournament);
        }

        return $tournaments;
    }

    /**
     * @return int[]
     */
    public function getSpecifiedIDs(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $this->tournamentIDs))));
    }

    /**
     * The Community Event Manager event of the current page (page attribute "event", or the page of the event).
     */
    public function getPageEventID(): ?int
    {
        if (!class_exists(\CommunityEventManager\Event\EventService::class)) {
            return null;
        }
        $page = Page::getCurrentPage();
        if (!$page || $page->isError()) {
            return null;
        }
        $events = $this->app->make(\CommunityEventManager\Event\EventService::class);
        $event = null;
        $calendarEvent = $page->getAttribute('event');
        if ($calendarEvent instanceof \Concrete\Core\Entity\Calendar\CalendarEvent) {
            $event = $events->getByCalendarEventID((int) $calendarEvent->getID());
        }
        if ($event === null) {
            $event = $events->getByPageID((int) $page->getCollectionID());
        }

        return $event ? $event->getID() : null;
    }

    public function getCurrentUser(): ?User
    {
        $user = $this->app->make(User::class);

        return $user->isRegistered() ? $user : null;
    }

    public function usesThemeUi(): bool
    {
        return $this->app->bound(self::THEME_BINDING);
    }

    /**
     * What every view gets: the presenter data of the tournaments, the token, the action URLs, flash messages.
     */
    protected function setCommonViewData(array $tournaments): void
    {
        $presenter = $this->app->make(Presenter::class);
        $user = $this->getCurrentUser();
        $data = [];
        foreach ($tournaments as $tournament) {
            $data[] = $presenter->tournamentData($tournament, $user);
        }
        $this->set('tournaments', $data);
        $this->set('user', $user);
        $this->set('presenter', $presenter);
        $this->set('token', $this->app->make(Token::class));
        $this->set('tokenName', Api::TOKEN);
        $this->set('actionBase', (string) \Concrete\Core\Support\Facade\Url::to('/ccm/challonge_tournaments'));
        $this->set('loginURL', (string) \Concrete\Core\Support\Facade\Url::to('/login'));
        $page = Page::getCurrentPage();
        $this->set('returnURL', $page && !$page->isError() ? (string) \Concrete\Core\Support\Facade\Url::to($page) : '/');
        $this->set('usesThemeUi', $this->usesThemeUi());
        $flash = $this->app->make('session')->getFlashBag();
        $this->set('messages', ['success' => $flash->get('challonge_tournaments.success'), 'error' => $flash->get('challonge_tournaments.error')]);
        if (!$this->usesThemeUi()) {
            $this->requireAsset('css', 'font-awesome');
            $this->requireAsset('javascript', 'challonge_tournaments/ui');
            $this->requireAsset('css', 'challonge_tournaments/ui');
        }
    }

    /**
     * Tournaments shown on a page are synced on the fly if their data is older than stale_after_minutes.
     */
    protected function refreshIfStale(Tournament $tournament): void
    {
        $minutes = $this->app->make(Settings::class)->getStaleAfterMinutes();
        if ($minutes <= 0 || !$tournament->isActive()) {
            return;
        }
        $last = $tournament->getLastSyncedAt();
        if ($last === null || $last < new \DateTime('-' . $minutes . ' minutes')) {
            $this->app->make(TournamentSync::class)->sync($tournament, false);
        }
    }

    /**
     * The choices of the block forms.
     *
     * @return array<int, string>
     */
    public function getTournamentChoices(): array
    {
        $choices = [];
        foreach ($this->app->make(TournamentRepository::class)->getAll(false) as $tournament) {
            $choices[$tournament->getID()] = $tournament->getName() . ' (' . Presenter::stateLabel($tournament->getState()) . ')';
        }

        return $choices;
    }

    public function getModeChoices(): array
    {
        return [
            self::MODE_EVENT => t('Tournaments of this page\'s event'),
            self::MODE_ALL => t('All tournaments'),
            self::MODE_SPECIFY => t('Chosen tournaments'),
        ];
    }

    protected function cleanTournamentIDs($input): string
    {
        $ids = array_filter(array_map('intval', (array) $input));

        return implode(',', array_unique($ids));
    }
}
