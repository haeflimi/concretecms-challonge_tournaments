<?php

/*
 * The tournaments the fake Challonge (ChallongeTournaments\Api\FakeClient) starts with. Loaded once into
 * application/files/challonge_fake/state.json; delete that file (or run dev/reset_fake.php) to start over.
 *
 * Keys: url (slug), name, tournament_type, game_name, starts_at, description, participants (names, or
 * ['name' => .., 'misc' => 'team:<id>']), start (build the bracket), results ([identifier, winning side 1|2, scores]),
 * underway (identifiers marked as underway), finalize (complete the tournament when every match is reported).
 */
return [
    [
        'url' => 'lan35-cs2',
        'name' => 'LAN 35 — Counter-Strike 2 5on5',
        'tournament_type' => 'single elimination',
        'game_name' => 'Counter-Strike 2',
        'starts_at' => '2026-11-07T14:00:00+01:00',
        'description' => 'Das Hauptturnier der LAN 35. Best of 1 bis zum Halbfinal, Final Best of 3.',
        'participants' => [],
    ],
    [
        'url' => 'lan35-rocket-league',
        'name' => 'LAN 35 — Rocket League 3v3',
        'tournament_type' => 'single elimination',
        'game_name' => 'Rocket League',
        'starts_at' => '2026-11-07T18:00:00+01:00',
        'description' => 'Best of 3, Final Best of 5.',
        'participants' => [
            'Zürich Zephyrs', 'Bern Bulldozers', 'Basel Boosters', 'Luzern Lightning',
            'Winterthur Wolves', 'St. Gallen Storm', 'Aarau Aces', 'Chur Comets',
        ],
        'start' => true,
        'results' => [
            ['A', 1, '3-1,3-2'],
            ['B', 2, '1-3,2-3'],
            ['C', 1, '3-0,3-1'],
            ['D', 1, '2-3,3-1,3-2'],
            ['E', 2, '1-3,3-2,0-3'],
        ],
        'underway' => ['F'],
    ],
    [
        'url' => 'lan35-trackmania-cup',
        'name' => 'LAN 35 — Trackmania Cup',
        'tournament_type' => 'round robin',
        'game_name' => 'Trackmania',
        'starts_at' => '2026-11-06T21:00:00+01:00',
        'description' => 'Jeder gegen jeden, drei Maps pro Match.',
        'participants' => ['speedy', 'driftking', 'nocut', 'hairpin'],
        'start' => true,
        'results' => [
            ['A', 1, '2-1'], ['B', 2, '0-3'],
            ['C', 1, '3-0'], ['D', 1, '2-1'],
            ['E', 2, '1-2'], ['F', 1, '3-0'],
        ],
        'finalize' => true,
    ],
];
