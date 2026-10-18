# Challonge Tournaments

Concrete CMS 9.5 package: tournaments hosted on [challonge.com](https://challonge.com) on the site. Challonge owns the
tournament (format, rules, bracket); this package links it to an event of the **Community Event Manager**, seeds it
from a team pool of the **Team Manager**, shows participants, matches, standings and the bracket in blocks, and lets
captains report their results, which are written back to Challonge after confirmation.

Both other packages are soft dependencies: without them tournaments can still be linked and shown.

## How it works

1. **Create the tournament on challonge.com** (type, game, best-of, start). Copy its URL.
2. **Dashboard › Tournaments › Link tournament:** paste the URL, choose the event and the team pool. The tournament,
   its participants and matches are read from the Challonge API (v2.1) into the tables `ctTournament`,
   `ctParticipant`, `ctMatch`.
3. **Seed:** while the tournament is pending, the pool's teams (and singles, if the pool allows them) are listed;
   tick, order (drag) or randomise, "Add to Challonge". The link team ↔ participant is stored in the participant's
   `misc` field on Challonge (`team:12` / `user:5`), so it survives re-syncs and unlinking.
4. **Start / finalize** from the dashboard (or on Challonge). The blocks show the matches.
5. **Results:** a captain of either team reports (winner + scores per map, `16-9,10-16,16-12`). The other team's
   captain or a moderator confirms → the result is written to Challonge. Both captains reporting the same result
   confirms it at once; a differing report puts both in front of the moderators. Moderators report directly.
6. **Sync:** the task *Sync Challonge tournaments* (`challonge_sync`, console `challonge-sync`) pulls tournaments that
   may still change and are older than `sync_interval_minutes`. Pages and the dashboard also sync a running
   tournament whose data is older than `stale_after_minutes`. Schedule the task in System › Automation.

### Request budget

Free Challonge API apps are limited to **500 requests per month** (429 beyond that from July 2026). A sync is 3
requests (tournament, participants, matches), seeding 1 per 20 participants, a result 2 (read + write). Nothing on a
page view calls Challonge unless the data is stale. For a LAN weekend with a few tournaments, set the interval to
5–10 minutes or use a paid plan. The settings page shows the limits.

## Blocks

| Block | What |
|---|---|
| **Tournament List** | Cards of the event's (or all / chosen) tournaments: game, type, state, participants, live count, winner. Links to a page with the overview block (`?tournament=<id>`) or to Challonge. |
| **Tournament Overview** | One tournament in full (several → tabs): live / upcoming / finished matches with reporting, standings (final ranks when complete, else W/L and map difference), participants, embedded Challonge bracket (`/module` iframe). Polls `/ccm/challonge_tournaments/state/<id>` and reloads when the tournament changed. |
| **My Matches** | The signed-in player's matches across the running tournaments (team member or single), with reporting for captains. For `/account` and the live dashboard. |

"Which tournaments": the page's event (page attribute `event` or the event's page, as the event blocks do), all, or
chosen ones.

## Dashboard

`/dashboard/tournaments`: list by event (with pending reports), link, per tournament: state transitions, participants
(reseed, remove), seeding from the pool, matches (mark live, report, reopen), result reports (confirm / reject),
settings (event, pool, moderator group, visibility, reporting). `/dashboard/tournaments/settings`: API key, default
community, fake client, sync intervals, bracket embed theme/size, auto-confirm, global moderator group.

**Who may what** (`Tournament\Access`): administrators (anyone who may view the dashboard page) and members of the
global or the tournament's moderator group moderate. Captains of a team (`tmTeamMember.captain`) or the single player
report and confirm for their side. Team members see "your team" marks.

## API client

`Api\ClientInterface` — `HttpClient` (real, v1 API key: `Authorization-Type: v1`, JSON:API, paths end in `.json`,
`page`/`per_page` pagination, bulk add in chunks of 20) and `FakeClient` (an in-memory Challonge, state in
`application/files/challonge_fake/state.json`, fixtures in `dev/fixtures/fake-tournaments.php`: single elimination
with byes and round robin, start / report / reopen / finalize / reset). The fake is used when no key is configured or
`fake_client` is on. `docs/challonge-api-v2.1.md` is the compiled API reference with the open questions
("UNCLEAR") to verify against the live API.

## Configuration

`config/settings.php` holds the defaults; the dashboard writes to
`application/config/generated_overrides/challonge_tournaments/settings.php`. The API key is only ever shown masked.

## Theme integration

The views are Bootstrap 5 (`blocks/*/view.php`, `js/tournaments.js`, `css/tournaments.css`). A theme that renders the
blocks itself binds `challonge_tournaments.ui` in the container (`$app->instance('challonge_tournaments.ui', 'blackout')`);
the built-in assets are then not loaded. All views receive the presenter data (`Tournament\Presenter::tournamentData()`),
the token (`challonge_tournaments`), `$actionBase` (`/ccm/challonge_tournaments`) and `$returnURL`. Theme Blackout ships
templates in `theme_blackout/themes/blackout/blocks/tournament_*`.

## Events

- `on_challonge_tournament_synced` — subject `Tournament`, argument `result` (`Sync\SyncResult`: diff of state,
  matches, participants). Only when something changed.
- `on_challonge_result_reported` / `on_challonge_result_applied` — subject `ScoreReport`.
- Event figures (`Event Figure` block): tournaments, participants, matches played, matches live, tournament winner.

See `docs/live-later.md` for the Mercure plan.

## Development

- `dev/seed_dev.php` links the fake tournaments to the dev event and a team pool (creates the pool and teams if
  needed); `dev/reset_fake.php` resets the fake Challonge. Run with
  `docker exec -u www-data devturicanech-app-1 php /app/concrete/bin/concrete c5:exec --no-interaction /app/packages/challonge_tournaments/dev/<script>`.
- Translations: `c5:package-translate challonge_tournaments -l de_DE -l de_CH -x`, translate, run again (compiles
  the .mo files), `c5:clear-cache`. German in `de_DE` (German spelling) and `de_CH` (Swiss spelling, «» quotes),
  informal "du".
- After PHP changes run `c5:clear-cache` (the Doctrine metadata and the overrides cache).
