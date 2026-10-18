<?php

namespace ChallongeTournaments\Controller;

use ChallongeTournaments\Entity\ScoreReport;
use ChallongeTournaments\Score\ScoreService;
use ChallongeTournaments\Tournament\Access;
use ChallongeTournaments\Tournament\Presenter;
use ChallongeTournaments\Tournament\TournamentRepository;
use Concrete\Core\Controller\AbstractController;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Http\ResponseFactoryInterface;
use Concrete\Core\User\User;
use Concrete\Core\Validation\CSRF\Token;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The AJAX endpoints of the blocks (/ccm/challonge_tournaments/...): the current state of a tournament for
 * polling, and the result reporting actions. All writes are POSTs with the token "challonge_tournaments".
 */
class Api extends AbstractController
{
    const TOKEN = 'challonge_tournaments';

    /**
     * GET: the tournament's matches and standings as the blocks render them, plus lastChangedAt for cheap polling
     * (no Challonge request is made here; the data is what the last sync stored).
     */
    public function state(int $tournamentID): Response
    {
        $tournament = $this->app->make(TournamentRepository::class)->getByID($tournamentID);
        if ($tournament === null || !$tournament->isVisible()) {
            return $this->json(['success' => false, 'message' => t('Unknown tournament.')], 404);
        }
        $presenter = $this->app->make(Presenter::class);
        $user = $this->app->make(User::class);
        $response = $this->json([
            'success' => true,
            'tournament' => $presenter->tournamentData($tournament, $user->isRegistered() ? $user : null),
        ]);
        $response->headers->set('Cache-Control', 'private, max-age=15');

        return $response;
    }

    public function report(int $matchID): Response
    {
        return $this->action(function (User $user) use ($matchID) {
            $match = $this->app->make(TournamentRepository::class)->getMatch($matchID);
            if ($match === null) {
                throw new UserMessageException(t('Unknown match.'));
            }
            $post = $this->request->request;
            $service = $this->app->make(ScoreService::class);
            if ($post->has('underway')) {
                $service->setUnderway($match, $user, (bool) $post->getInt('underway'));

                return t('Match marked as %s.', $post->getInt('underway') ? t('underway') : t('not underway'));
            }
            if ($post->has('reopen')) {
                $service->reopen($match, $user);

                return t('Match reopened.');
            }
            $report = $service->report($match, $user, $post->getInt('winner'), (string) $post->get('scores', ''), $post->get('note'));

            return $report->getStatus() === ScoreReport::STATUS_APPLIED
                ? t('Result written to Challonge.')
                : t('Result reported. It is written to Challonge once the other team or a moderator confirms it.');
        });
    }

    public function confirm(int $reportID): Response
    {
        return $this->action(function (User $user) use ($reportID) {
            $this->app->make(ScoreService::class)->confirm($this->requireReport($reportID), $user);

            return t('Result confirmed and written to Challonge.');
        });
    }

    public function reject(int $reportID): Response
    {
        return $this->action(function (User $user) use ($reportID) {
            $this->app->make(ScoreService::class)->reject($this->requireReport($reportID), $user, $this->request->request->get('note'));

            return t('Report rejected.');
        });
    }

    public function withdraw(int $reportID): Response
    {
        return $this->action(function (User $user) use ($reportID) {
            $this->app->make(ScoreService::class)->withdraw($this->requireReport($reportID), $user);

            return t('Report withdrawn.');
        });
    }

    protected function requireReport(int $reportID): ScoreReport
    {
        $report = $this->app->make(TournamentRepository::class)->getReport($reportID);
        if ($report === null) {
            throw new UserMessageException(t('Unknown report.'));
        }

        return $report;
    }

    /**
     * POST only, token checked, signed-in users only. The callback returns the success message. Form posts are
     * redirected back to the page with a flash message; AJAX posts get JSON.
     */
    protected function action(callable $callback): Response
    {
        $ajax = $this->request->isXmlHttpRequest() || strpos((string) $this->request->headers->get('Accept'), 'application/json') !== false;
        try {
            if (!$this->request->isMethod('POST')) {
                throw new UserMessageException(t('Invalid request method.'));
            }
            $token = $this->app->make(Token::class);
            if (!$token->validate(self::TOKEN)) {
                throw new UserMessageException($token->getErrorMessage());
            }
            $user = $this->app->make(User::class);
            if (!$user->isRegistered()) {
                throw new UserMessageException(t('Sign in to report results.'));
            }
            $message = $callback($user);
        } catch (UserMessageException $e) {
            if ($ajax) {
                return $this->json(['success' => false, 'message' => $e->getMessage()], 400);
            }
            $this->flash('error', $e->getMessage());

            return $this->back();
        }
        if ($ajax) {
            $tournamentID = $this->request->request->getInt('tournament');
            $data = ['success' => true, 'message' => $message];
            if ($tournamentID > 0) {
                $tournament = $this->app->make(TournamentRepository::class)->getByID($tournamentID);
                if ($tournament !== null) {
                    $data['tournament'] = $this->app->make(Presenter::class)->tournamentData($tournament, $this->app->make(User::class));
                }
            }

            return $this->json($data);
        }
        $this->flash('success', $message);

        return $this->back();
    }

    protected function flash(string $type, string $message): void
    {
        $this->app->make('session')->getFlashBag()->add('challonge_tournaments.' . $type, $message);
    }

    protected function back(): Response
    {
        $return = (string) $this->request->request->get('return', '');
        $referer = (string) $this->request->headers->get('Referer', '');
        $url = $return !== '' && $return[0] === '/' ? $return : ($referer !== '' ? $referer : '/');
        // only paths of this site
        $host = $this->request->getSchemeAndHttpHost();
        if (strpos($url, '//') !== false && strpos($url, $host) !== 0) {
            $url = '/';
        }

        return $this->app->make(ResponseFactoryInterface::class)->redirect($url, 302);
    }

    protected function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }
}
