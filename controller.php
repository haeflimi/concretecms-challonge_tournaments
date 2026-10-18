<?php

namespace Concrete\Package\ChallongeTournaments;

use ChallongeTournaments\Api\ClientFactory;
use ChallongeTournaments\Api\ClientInterface;
use ChallongeTournaments\Command\Task\SyncTournamentsController;
use ChallongeTournaments\Config\Settings;
use ChallongeTournaments\Figure\TournamentFigures;
use ChallongeTournaments\Tournament\Access;
use ChallongeTournaments\Tournament\TournamentRepository;
use Concrete\Core\Asset\Asset;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Backup\ContentImporter;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Command\Task\Manager as TaskManager;
use Concrete\Core\Database\EntityManager\Provider\ProviderAggregateInterface;
use Concrete\Core\Database\EntityManager\Provider\StandardPackageProvider;
use Concrete\Core\Package\Package;
use Concrete\Core\Routing\Router;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Challonge Tournaments: tournaments hosted on challonge.com, linked to the events of the Community Event Manager and
 * seeded with the teams of the Team Manager. Blocks show the participants, matches, standings and the bracket on the
 * site; captains report their results, which moderators confirm and which are written back to Challonge.
 */
class Controller extends Package implements ProviderAggregateInterface
{
    protected $pkgHandle = 'challonge_tournaments';
    protected $appVersionRequired = '9.5.0';
    protected $phpVersionRequired = '8.1';
    protected $pkgVersion = '0.1.0';
    protected $pkgAutoloaderRegistries = [
        'src' => 'ChallongeTournaments',
    ];

    public function getPackageName()
    {
        return t('Challonge Tournaments');
    }

    public function getPackageDescription()
    {
        return t('Tournaments hosted on challonge.com: linked to events, seeded from the team pools, with participants, matches, standings and the bracket on the site and result reporting by the captains.');
    }

    public function getEntityManagerProvider()
    {
        return new StandardPackageProvider($this->app, $this, [
            'src/Entity' => 'ChallongeTournaments\Entity',
        ]);
    }

    public function on_start()
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(TournamentRepository::class);
        $this->app->singleton(Access::class);
        // the API client: the real one when a key is configured, the fake one on dev (see config/settings.php)
        $this->app->singleton(ClientInterface::class, function ($app) {
            return $app->make(ClientFactory::class)->create();
        });

        $router = $this->app->make(Router::class);
        $api = 'ChallongeTournaments\Controller\Api::';
        $router->get('/ccm/challonge_tournaments/state/{tournamentID}', $api . 'state')->setRequirement('tournamentID', '[0-9]+');
        $router->post('/ccm/challonge_tournaments/report/{matchID}', $api . 'report')->setRequirement('matchID', '[0-9]+');
        $router->post('/ccm/challonge_tournaments/confirm/{reportID}', $api . 'confirm')->setRequirement('reportID', '[0-9]+');
        $router->post('/ccm/challonge_tournaments/reject/{reportID}', $api . 'reject')->setRequirement('reportID', '[0-9]+');
        $router->post('/ccm/challonge_tournaments/withdraw/{reportID}', $api . 'withdraw')->setRequirement('reportID', '[0-9]+');

        $this->app->make(TaskManager::class)->extend('challonge_sync', function () {
            return $this->app->make(SyncTournamentsController::class);
        });

        // event figures of the Community Event Manager (soft dependency: only if that package is installed)
        $this->app->make('director')->addListener('on_event_figures_register', static function ($event) {
            TournamentFigures::register($event->getSubject());
        });

        $assets = AssetList::getInstance();
        $options = ['version' => $this->pkgVersion, 'position' => Asset::ASSET_POSITION_FOOTER, 'minify' => false, 'combine' => false];
        $assets->register('javascript', 'challonge_tournaments/ui', 'js/tournaments.js', $options, $this);
        $assets->register('css', 'challonge_tournaments/ui', 'css/tournaments.css', ['version' => $this->pkgVersion, 'minify' => false, 'combine' => false], $this);
    }

    public function install()
    {
        $pkg = parent::install();
        $this->installContent();

        return $pkg;
    }

    public function upgrade()
    {
        parent::upgrade();
        $this->installContent();
    }

    protected function installContent(): void
    {
        $importer = new ContentImporter();
        $importer->importContentFile($this->getPackagePath() . '/install.xml');
        // new db.xml columns of the blocks
        foreach (['tournament_list', 'tournament_overview', 'my_matches'] as $handle) {
            $bt = BlockType::getByHandle($handle);
            if ($bt) {
                $bt->refresh();
            }
        }
    }
}
