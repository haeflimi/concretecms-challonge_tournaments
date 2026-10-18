<?php

/*
 * Defaults of the Challonge Tournaments package. The dashboard page saves changes into
 * application/config/generated_overrides/challonge_tournaments/settings.php; a developer may also override them in
 * application/config/challonge_tournaments/settings.php.
 */
return [
    // Challonge API v1 key from https://connect.challonge.com (sent as "Authorization-Type: v1"). Empty = fake client.
    'api_key' => '',

    // Force the fake client (an in-memory Challonge driven by fixtures and persisted in application/files) even if a
    // key is set. Dev only.
    'fake_client' => false,

    // Challonge community (subdomain) the tournaments live in; empty for tournaments of the account itself.
    // Tournaments linked by URL carry their own community, this is only the default of the add form.
    'community' => '',

    // Minutes between two automatic syncs of a running tournament (the task "challonge_sync" runs more often but skips
    // tournaments synced within this interval). Free API apps have 500 requests per month: keep this high.
    'sync_interval_minutes' => 10,

    // Minutes after which a tournament shown on a page is synced on the fly if it is older than that (0 = never).
    'stale_after_minutes' => 30,

    // Bracket embed: the theme of the Challonge module (number or empty) and the size multiplier
    'embed_theme' => '',
    'embed_multiplier' => '1.0',

    // Score reports: a captain's report is written to Challonge after the opponent captain or a moderator confirmed
    // it. With auto_confirm_minutes > 0 an unopposed report is written automatically after that many minutes.
    'auto_confirm_minutes' => 0,

    // Group whose members moderate every tournament (in addition to administrators and per-tournament moderators).
    'moderator_group_id' => 0,
];
