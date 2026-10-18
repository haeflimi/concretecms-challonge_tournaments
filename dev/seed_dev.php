<?php
// Dev only: links the fake Challonge tournaments (dev/fixtures/fake-tournaments.php) to the dev event and a team pool,
// creates the pool, 8 teams and 16 test users if they do not exist, and maps the running Rocket League fixture's
// participants to those teams so the captain flows can be tried. Safe to run again.
// Run: docker exec -u www-data devturicanech-app-1 php /app/concrete/bin/concrete c5:exec --no-interaction /app/packages/challonge_tournaments/dev/seed_dev.php
//
// Test users: tourney01 … tourney16, password "tourney", two per team, the odd one is the captain. Admin uID 1 links.

use ChallongeTournaments\Api\ClientInterface;
use ChallongeTournaments\Api\FakeClient;
use ChallongeTournaments\Config\Settings;
use ChallongeTournaments\Sync\TournamentSync;
use ChallongeTournaments\Tournament\TournamentRepository;
use ChallongeTournaments\Tournament\TournamentService;
use Concrete\Core\User\RegistrationService;
use Concrete\Core\User\UserInfoRepository;

$app = Concrete\Core\Support\Facade\Application::getFacadeApplication();
$out = static function (string $line) {
    echo $line, PHP_EOL;
};

$settings = $app->make(Settings::class);
if (!$settings->usesFakeClient()) {
    $out('A real API key is configured and the fake client is off: this script only seeds the fake Challonge. Enable "fake_client" in Dashboard › Tournaments › Settings first.');

    return;
}
$client = $app->make(ClientInterface::class);
if (!$client instanceof FakeClient) {
    $out('Unexpected client ' . get_class($client));

    return;
}

// ---- event: the next published event, "LAN 35" preferred
$eventID = null;
if (class_exists(\CommunityEventManager\Event\EventService::class)) {
    $events = $app->make(\CommunityEventManager\Event\EventService::class);
    $candidates = $events->getList(['status' => [\CommunityEventManager\Entity\Event::STATUS_PUBLISHED, \CommunityEventManager\Entity\Event::STATUS_DRAFT], 'upcoming' => true]);
    if ($candidates === []) {
        $candidates = $events->getList(['status' => [\CommunityEventManager\Entity\Event::STATUS_PUBLISHED], 'limit' => 5]);
    }
    foreach ($candidates as $event) {
        if ($eventID === null || stripos($event->getName(), 'LAN 35') !== false) {
            $eventID = $event->getID();
            $eventName = $event->getName();
        }
    }
    $out($eventID ? "Event: #$eventID $eventName" : 'No event found; tournaments are linked without event.');
} else {
    $out('Community Event Manager not installed; tournaments are linked without event.');
}

// ---- users and teams
$teamNames = [
    'Zürich Zephyrs' => 'ZZ', 'Bern Bulldozers' => 'BB', 'Basel Boosters' => 'BST', 'Luzern Lightning' => 'LL',
    'Winterthur Wolves' => 'WW', 'St. Gallen Storm' => 'SGS', 'Aarau Aces' => 'AA', 'Chur Comets' => 'CC',
];
$users = $app->make(UserInfoRepository::class);
$registration = $app->make(RegistrationService::class);
$testUsers = [];
for ($i = 1; $i <= 16; $i++) {
    $name = sprintf('tourney%02d', $i);
    $ui = $users->getByName($name);
    if ($ui === null) {
        $ui = $registration->create(['uName' => $name, 'uEmail' => $name . '@example.test', 'uPassword' => 'tourney', 'uPasswordConfirm' => 'tourney', 'uIsValidated' => 1]);
        $out("Created user $name (password: tourney)");
    }
    $testUsers[] = $ui;
}

$poolID = null;
if (class_exists(\TeamManager\Team\TeamPoolRepository::class)) {
    $pools = $app->make(\TeamManager\Team\TeamPoolRepository::class);
    $poolName = 'LAN 35 Turniere';
    $pool = null;
    foreach ($pools->getAll() as $candidate) {
        if ($candidate->getName() === $poolName) {
            $pool = $candidate;
        }
    }
    if ($pool === null) {
        $pool = $app->make(\TeamManager\Team\TeamPoolService::class)->create([
            'name' => $poolName, 'description' => 'Dev: Teams für die Fake-Challonge-Turniere',
            'open' => true, 'allowTeams' => true, 'allowTeamCreation' => true, 'allowSingles' => true, 'maxTeams' => 0, 'maxTeamSize' => 5,
        ]);
        $out("Created team pool \"$poolName\" (gID {$pool->getID()})");
    }
    $poolID = $pool->getID();
    $teams = $app->make(\TeamManager\Team\TeamRepository::class);
    $teamService = $app->make(\TeamManager\Team\TeamService::class)->asAdmin();
    $existing = [];
    foreach ($teams->findAll($pool) as $team) {
        $existing[$team->getName()] = $team;
    }
    $i = 0;
    $teamsByName = [];
    foreach ($teamNames as $name => $tag) {
        $captain = $testUsers[$i * 2];
        $mate = $testUsers[$i * 2 + 1];
        $i++;
        $team = $existing[$name] ?? null;
        if ($team === null) {
            $team = $teamService->create($name, $tag, 'Dev-Team für die Turniere', $captain, true, $pool);
            try {
                $teamService->addMember($team, $mate, $captain);
            } catch (\Throwable $e) {
                $out("  could not add {$mate->getUserName()} to $name: {$e->getMessage()}");
            }
            $out("Created team $name (captain {$captain->getUserName()}, member {$mate->getUserName()})");
        }
        $teamsByName[$name] = $team;
    }
} else {
    $out('Team Manager not installed; no pool, no teams.');
}

// ---- link the fake tournaments
$repository = $app->make(TournamentRepository::class);
$service = $app->make(TournamentService::class);
$sync = $app->make(TournamentSync::class);
foreach ($client->getSlugs() as $slug) {
    $resource = $client->getTournament($slug);
    $tournament = $repository->getByChallongeID((string) $resource['id']);
    if ($tournament === null) {
        $tournament = $service->link($slug, '', $eventID, $poolID, 1);
        $out("Linked $slug as #{$tournament->getID()} \"{$tournament->getName()}\"");
    } else {
        $service->update($tournament, ['eventID' => $eventID, 'poolID' => $poolID]);
        $out("Already linked: $slug (#{$tournament->getID()}), event/pool updated");
    }
    // the running fixture: map its participants to the dev teams by name (misc = team:<id>), so captains have matches
    if (!empty($teamsByName)) {
        $mapped = 0;
        foreach ($client->listParticipants($slug) as $participant) {
            $name = $participant['attributes']['name'];
            if (isset($teamsByName[$name]) && $participant['attributes']['misc'] !== 'team:' . $teamsByName[$name]->getID()) {
                $client->updateParticipant($slug, $participant['id'], ['misc' => 'team:' . $teamsByName[$name]->getID()]);
                $mapped++;
            }
        }
        if ($mapped > 0) {
            $out("  mapped $mapped participants to dev teams");
        }
    }
    $result = $sync->sync($tournament, false);
    $out('  sync: ' . $result->summary());
}
$out('Done. Dashboard: /dashboard/tournaments — captains: tourney01 (Zürich Zephyrs), tourney03 (Bern Bulldozers), … password "tourney".');
