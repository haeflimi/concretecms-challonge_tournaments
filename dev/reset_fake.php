<?php
// Dev only: resets the fake Challonge to its fixtures (application/files/challonge_fake/state.json is rebuilt) and
// re-syncs the linked tournaments. The fixture IDs are deterministic, so the links stay valid; run seed_dev.php again
// to map the Rocket League participants to the dev teams.
// Run: docker exec -u www-data devturicanech-app-1 php /app/concrete/bin/concrete c5:exec --no-interaction /app/packages/challonge_tournaments/dev/reset_fake.php

use ChallongeTournaments\Api\ClientInterface;
use ChallongeTournaments\Api\FakeClient;
use ChallongeTournaments\Sync\TournamentSync;
use ChallongeTournaments\Tournament\TournamentRepository;

$app = Concrete\Core\Support\Facade\Application::getFacadeApplication();
$client = $app->make(ClientInterface::class);
if (!$client instanceof FakeClient) {
    echo "The real client is active; nothing to reset.\n";

    return;
}
$client->reset();
echo "Fake Challonge reset: " . implode(', ', $client->getSlugs()) . "\n";
$sync = $app->make(TournamentSync::class);
foreach ($app->make(TournamentRepository::class)->getAll(false) as $tournament) {
    $result = $sync->sync($tournament, false);
    echo $tournament->getName() . ': ' . $result->summary() . "\n";
}
