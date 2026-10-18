<?php

namespace Concrete\Package\ChallongeTournaments\Controller\SinglePage\Dashboard;

use ChallongeTournaments\Api\ClientInterface;
use ChallongeTournaments\Api\FakeClient;
use ChallongeTournaments\Api\TournamentState;
use ChallongeTournaments\Config\Settings;
use ChallongeTournaments\Entity\Tournament;
use ChallongeTournaments\Score\ScoreService;
use ChallongeTournaments\Seeding\Seeder;
use ChallongeTournaments\Sync\TournamentSync;
use ChallongeTournaments\Tournament\Presenter;
use ChallongeTournaments\Tournament\TournamentRepository;
use ChallongeTournaments\Tournament\TournamentService;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\User\Group\GroupList;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Dashboard › Tournaments: link Challonge tournaments to events and team pools, seed them, sync, change their state,
 * handle the result reports, and the package settings. One view file, switched by $mode.
 */
class Tournaments extends DashboardPageController
{
    const TOKEN = 'challonge_dashboard';
    const PATH = '/dashboard/tournaments';

    public function view()
    {
        $repository = $this->app->make(TournamentRepository::class);
        $tournaments = $repository->getAll(false);
        $byEvent = [];
        foreach ($tournaments as $tournament) {
            $byEvent[$tournament->getEventID() ?? 0][] = $tournament;
        }
        $this->set('mode', 'list');
        $this->set('tournamentsByEvent', $byEvent);
        $this->set('eventNames', $this->eventNames());
        $this->set('pendingReports', $repository->getPendingReports());
        $this->set('client', $this->app->make(ClientInterface::class));
        $this->set('settings', $this->app->make(Settings::class));
        $this->common();
    }

    public function add()
    {
        $this->set('mode', 'add');
        $this->set('events', $this->eventChoices());
        $this->set('pools', $this->poolChoices());
        $this->set('settings', $this->app->make(Settings::class));
        $client = $this->app->make(ClientInterface::class);
        $this->set('fakeSlugs', $client instanceof FakeClient ? $client->getSlugs() : []);
        $this->common();
    }

    public function link()
    {
        return $this->respond(function () {
            $post = $this->request->request;
            $service = $this->app->make(TournamentService::class);
            $tournament = $service->link(
                (string) $post->get('reference', ''),
                (string) $post->get('community', ''),
                $post->getInt('eventID') ?: null,
                $post->getInt('poolID') ?: null,
                (int) $this->app->make(User::class)->getUserID()
            );
            $this->redirectTo = self::PATH . '/tournament/' . $tournament->getID();

            return t('Tournament "%s" linked.', $tournament->getName());
        });
    }

    public function tournament($id = null)
    {
        $tournament = $this->requireTournament((int) $id);
        if ($tournament instanceof Response) {
            return $tournament;
        }
        $this->refreshIfStale($tournament);
        $this->set('mode', 'tournament');
        $this->set('tournament', $tournament);
        $this->set('data', $this->app->make(Presenter::class)->tournamentData($tournament, $this->app->make(User::class)));
        $this->set('events', $this->eventChoices());
        $this->set('pools', $this->poolChoices());
        $this->set('groups', $this->groupChoices());
        $this->set('eventNames', $this->eventNames());
        $seeder = $this->app->make(Seeder::class);
        $this->set('seedingAvailable', $seeder->isAvailable());
        $this->set('candidates', $seeder->getCandidates($tournament));
        $this->set('transitions', TournamentState::transitionsFor($tournament->getState(), $tournament->isGroupStagesEnabled()));
        $repository = $this->app->make(TournamentRepository::class);
        $this->set('pendingReports', $repository->getPendingReports($tournament));
        $this->set('recentReports', $repository->getRecentReports($tournament, 15));
        $this->set('userNames', $this->userNames(array_merge($repository->getPendingReports($tournament), $repository->getRecentReports($tournament, 15))));
        $this->set('client', $this->app->make(ClientInterface::class));
        $this->set('settings', $this->app->make(Settings::class));
        $this->common();
    }

    public function save_tournament($id = null)
    {
        return $this->respond(function () use ($id) {
            $tournament = $this->requireTournamentOrFail((int) $id);
            $post = $this->request->request;
            $this->app->make(TournamentService::class)->update($tournament, [
                'eventID' => $post->getInt('eventID') ?: null,
                'poolID' => $post->getInt('poolID') ?: null,
                'visible' => (bool) $post->getInt('visible'),
                'reportingEnabled' => (bool) $post->getInt('reportingEnabled'),
                'moderatorGroupID' => $post->getInt('moderatorGroupID') ?: null,
                'position' => $post->getInt('position'),
            ]);

            return t('Tournament settings saved.');
        }, $id);
    }

    public function sync($id = null)
    {
        return $this->respond(function () use ($id) {
            $tournament = $this->requireTournamentOrFail((int) $id);
            try {
                $result = $this->app->make(TournamentSync::class)->sync($tournament, true);
            } catch (\ChallongeTournaments\Api\ApiException $e) {
                throw new UserMessageException($e->getMessage());
            }

            return t('Synced with Challonge: %s', $result->summary());
        }, $id);
    }

    public function unlink($id = null)
    {
        return $this->respond(function () use ($id) {
            $tournament = $this->requireTournamentOrFail((int) $id);
            $name = $tournament->getName();
            $this->app->make(TournamentService::class)->unlink($tournament);
            $this->redirectTo = self::PATH;

            return t('Tournament "%s" unlinked. It still exists on Challonge.', $name);
        });
    }

    public function change_state($id = null)
    {
        return $this->respond(function () use ($id) {
            $tournament = $this->requireTournamentOrFail((int) $id);
            $state = (string) $this->request->request->get('state', '');
            $allowed = TournamentState::transitionsFor($tournament->getState(), $tournament->isGroupStagesEnabled());
            if (!isset($allowed[$state])) {
                throw new UserMessageException(t('This state change is not possible right now.'));
            }
            $this->app->make(TournamentService::class)->changeState($tournament, $state);

            return t('%s: done. The tournament is now "%s".', $allowed[$state], Presenter::stateLabel($tournament->getState()));
        }, $id);
    }

    public function seed($id = null)
    {
        return $this->respond(function () use ($id) {
            $tournament = $this->requireTournamentOrFail((int) $id);
            $keys = array_values(array_filter(array_map('strval', (array) $this->request->request->all('keys')), static function ($key) {
                return preg_match('/^(team|user):\d+$/', $key) === 1;
            }));
            if ($keys === []) {
                throw new UserMessageException(t('Choose at least one team.'));
            }
            if ($this->request->request->get('order') === 'random') {
                shuffle($keys);
            }
            $added = $this->app->make(Seeder::class)->seed($tournament, $keys);

            return t2('%d participant added to Challonge.', '%d participants added to Challonge.', $added);
        }, $id);
    }

    public function remove_participant($id = null, $participantID = null)
    {
        return $this->respond(function () use ($id, $participantID) {
            $tournament = $this->requireTournamentOrFail((int) $id);
            $participant = $tournament->getParticipantByChallongeID((string) $participantID);
            if ($participant === null) {
                throw new UserMessageException(t('Unknown participant.'));
            }
            $name = $participant->getName();
            $this->app->make(Seeder::class)->remove($tournament, $participant);

            return t('"%s" removed from the tournament.', $name);
        }, $id);
    }

    public function reseed($id = null, $participantID = null)
    {
        return $this->respond(function () use ($id, $participantID) {
            $tournament = $this->requireTournamentOrFail((int) $id);
            $participant = $tournament->getParticipantByChallongeID((string) $participantID);
            if ($participant === null) {
                throw new UserMessageException(t('Unknown participant.'));
            }
            $this->app->make(Seeder::class)->reseed($tournament, $participant, $this->request->request->getInt('seed'));

            return t('Seed of "%s" changed.', $participant->getName());
        }, $id);
    }

    public function shuffle($id = null)
    {
        return $this->respond(function () use ($id) {
            $this->app->make(Seeder::class)->shuffle($this->requireTournamentOrFail((int) $id));

            return t('Seeds shuffled.');
        }, $id);
    }

    public function clear_participants($id = null)
    {
        return $this->respond(function () use ($id) {
            $this->app->make(Seeder::class)->clear($this->requireTournamentOrFail((int) $id));

            return t('All participants removed from the tournament.');
        }, $id);
    }

    public function report_match($id = null, $matchID = null)
    {
        return $this->respond(function () use ($id, $matchID) {
            $tournament = $this->requireTournamentOrFail((int) $id);
            $match = $this->app->make(TournamentRepository::class)->getMatch((int) $matchID);
            if ($match === null || $match->getTournament()->getID() !== $tournament->getID()) {
                throw new UserMessageException(t('Unknown match.'));
            }
            $post = $this->request->request;
            $service = $this->app->make(ScoreService::class);
            $user = $this->app->make(User::class);
            if ($post->has('reopen')) {
                $service->reopen($match, $user);

                return t('Match %s reopened.', $match->getIdentifier());
            }
            if ($post->has('underway')) {
                $service->setUnderway($match, $user, (bool) $post->getInt('underway'));

                return t('Match %s updated.', $match->getIdentifier());
            }
            $service->report($match, $user, $post->getInt('winner'), (string) $post->get('scores', ''), $post->get('note'));

            return t('Result of match %s written to Challonge.', $match->getIdentifier());
        }, $id);
    }

    public function confirm_report($id = null, $reportID = null)
    {
        return $this->respond(function () use ($id, $reportID) {
            $this->requireTournamentOrFail((int) $id);
            $report = $this->app->make(TournamentRepository::class)->getReport((int) $reportID);
            if ($report === null) {
                throw new UserMessageException(t('Unknown report.'));
            }
            $this->app->make(ScoreService::class)->confirm($report, $this->app->make(User::class));

            return t('Result confirmed and written to Challonge.');
        }, $id);
    }

    public function reject_report($id = null, $reportID = null)
    {
        return $this->respond(function () use ($id, $reportID) {
            $this->requireTournamentOrFail((int) $id);
            $report = $this->app->make(TournamentRepository::class)->getReport((int) $reportID);
            if ($report === null) {
                throw new UserMessageException(t('Unknown report.'));
            }
            $this->app->make(ScoreService::class)->reject($report, $this->app->make(User::class), $this->request->request->get('note'));

            return t('Report rejected.');
        }, $id);
    }

    public function settings()
    {
        $this->set('mode', 'settings');
        $this->set('settings', $this->app->make(Settings::class));
        $this->set('groups', $this->groupChoices());
        $client = $this->app->make(ClientInterface::class);
        $this->set('client', $client);
        $this->set('fakeSlugs', $client instanceof FakeClient ? $client->getSlugs() : []);
        $this->common();
    }

    public function save_settings()
    {
        return $this->respond(function () {
            $post = $this->request->request;
            $settings = $this->app->make(Settings::class);
            $key = trim((string) $post->get('api_key', ''));
            if ($post->getInt('clear_api_key')) {
                $settings->save('api_key', '');
            } elseif ($key !== '') {
                // only a new key replaces the stored one; the form never shows the stored key
                $settings->save('api_key', $key);
            }
            $settings->save('fake_client', (bool) $post->getInt('fake_client'));
            $community = trim((string) $post->get('community', ''));
            if ($community !== '' && !preg_match('/^[a-z0-9-]+$/i', $community)) {
                throw new UserMessageException(t('The community is the subdomain only, e.g. "turicane" for turicane.challonge.com.'));
            }
            $settings->save('community', strtolower($community));
            $settings->save('sync_interval_minutes', max(1, $post->getInt('sync_interval_minutes')));
            $settings->save('stale_after_minutes', max(0, $post->getInt('stale_after_minutes')));
            $settings->save('embed_theme', preg_replace('/[^0-9]/', '', (string) $post->get('embed_theme', '')));
            $multiplier = (float) str_replace(',', '.', (string) $post->get('embed_multiplier', '1'));
            $settings->save('embed_multiplier', $multiplier > 0 && $multiplier <= 3 ? (string) $multiplier : '1');
            $settings->save('auto_confirm_minutes', max(0, $post->getInt('auto_confirm_minutes')));
            $settings->save('moderator_group_id', $post->getInt('moderator_group_id'));
            if ($post->getInt('reset_fake')) {
                $client = $this->app->make(ClientInterface::class);
                if ($client instanceof FakeClient) {
                    $client->reset();
                }
            }
            $this->redirectTo = self::PATH . '/settings';

            return t('Settings saved.');
        });
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    protected function common(): void
    {
        $this->set('token', $this->token);
        $this->set('tokenAction', self::TOKEN);
        $this->set('basePath', self::PATH);
        $this->set('presenter', $this->app->make(Presenter::class));
        $this->requireAsset('javascript', 'challonge_tournaments/ui');
        $this->requireAsset('css', 'challonge_tournaments/ui');
    }

    /**
     * @return Tournament|Response
     */
    protected function requireTournament(int $id)
    {
        $tournament = $this->app->make(TournamentRepository::class)->getByID($id);
        if ($tournament === null) {
            $this->flash('error', t('Unknown tournament.'));

            return $this->buildRedirect(self::PATH);
        }

        return $tournament;
    }

    /**
     * @throws UserMessageException
     */
    protected function requireTournamentOrFail(int $id): Tournament
    {
        $tournament = $this->app->make(TournamentRepository::class)->getByID($id);
        if ($tournament === null) {
            throw new UserMessageException(t('Unknown tournament.'));
        }

        return $tournament;
    }

    /**
     * Active tournaments shown in the dashboard are synced on the fly if older than stale_after_minutes.
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

    /** @var string|null */
    protected $redirectTo;

    /**
     * Runs a write action: POST only, token checked, UserMessageExceptions become error messages; back to the
     * tournament page (or where redirectTo points).
     */
    protected function respond(callable $callback, $tournamentID = null): Response
    {
        $ajax = $this->request->isXmlHttpRequest();
        $return = $tournamentID ? self::PATH . '/tournament/' . (int) $tournamentID : self::PATH;
        try {
            if (!$this->request->isMethod('POST')) {
                throw new UserMessageException(t('Invalid request method.'));
            }
            if (!$this->token->validate(self::TOKEN)) {
                throw new UserMessageException($this->token->getErrorMessage());
            }
            $message = $callback();
        } catch (UserMessageException $e) {
            if ($ajax) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());

            return $this->buildRedirect($this->redirectTo ?: $return);
        }
        if ($ajax) {
            return new JsonResponse(['success' => true, 'message' => $message]);
        }
        $this->flash('success', $message);

        return $this->buildRedirect($this->redirectTo ?: $return);
    }

    /**
     * @return array<int, string> event ID => label (upcoming and recent events, drafts included)
     */
    protected function eventChoices(): array
    {
        $choices = [0 => t('— no event —')];
        if (!class_exists(\CommunityEventManager\Event\EventService::class)) {
            return $choices;
        }
        $events = $this->app->make(\CommunityEventManager\Event\EventService::class);
        $list = $events->getList(['status' => [\CommunityEventManager\Entity\Event::STATUS_DRAFT, \CommunityEventManager\Entity\Event::STATUS_PUBLISHED], 'limit' => 60]);
        foreach ($list as $event) {
            $date = $event->getStartsAt() ? $event->getStartsAt()->format('d.m.Y') : '';
            $choices[$event->getID()] = $event->getName() . ($date ? ' (' . $date . ')' : '') . ($event->isDraft() ? ' — ' . t('draft') : '');
        }

        return $choices;
    }

    /**
     * @return array<int, string>
     */
    protected function eventNames(): array
    {
        $names = [];
        if (class_exists(\CommunityEventManager\Event\EventService::class)) {
            foreach ($this->app->make(\CommunityEventManager\Event\EventService::class)->getList([]) as $event) {
                $names[$event->getID()] = $event->getName();
            }
        }

        return $names;
    }

    /**
     * @return array<int, string> pool gID => label
     */
    protected function poolChoices(): array
    {
        $choices = [0 => t('— no team pool —')];
        if (!class_exists(\TeamManager\Team\TeamPoolRepository::class)) {
            return $choices;
        }
        $pools = $this->app->make(\TeamManager\Team\TeamPoolRepository::class);
        foreach ($pools->getAll() as $pool) {
            $choices[$pool->getID()] = $pool->getName() . ' (' . t2('%d team', '%d teams', $pools->countTeams($pool)) . ')';
        }

        return $choices;
    }

    /**
     * @return array<int, string>
     */
    protected function groupChoices(): array
    {
        $choices = [0 => t('— none —')];
        $list = new GroupList();
        $list->includeAllGroups();
        foreach ($list->getResults() as $group) {
            if ((int) $group->getGroupID() > 0) {
                $choices[(int) $group->getGroupID()] = $group->getGroupDisplayName(false);
            }
        }

        return $choices;
    }

    /**
     * @param \ChallongeTournaments\Entity\ScoreReport[] $reports
     *
     * @return array<int, string>
     */
    protected function userNames(array $reports): array
    {
        $ids = [];
        foreach ($reports as $report) {
            $ids[$report->getReportedBy()] = true;
            if ($report->getResolvedBy()) {
                $ids[$report->getResolvedBy()] = true;
            }
        }
        $names = [];
        $users = $this->app->make(UserInfoRepository::class);
        foreach (array_keys($ids) as $uID) {
            $ui = $uID > 0 ? $users->getByID($uID) : null;
            $names[$uID] = $ui ? $ui->getUserName() : t('system');
        }

        return $names;
    }
}
