# Live updates (Mercure) — later

The package is built so that a live layer can be added without touching the sync or the blocks. Nothing here is
implemented yet; this is the plan and the hooks that exist.

## What exists now

- **Change detection.** `TournamentSync::sync()` hashes the relevant fields (tournament state, participants,
  every match's state/players/winner/scores/underway flag). When the hash changes it stores `lastChangedAt` and
  dispatches `on_challonge_tournament_synced` with a `SyncResult` (`getDiff()`: state change, changed match IDs with
  before/after, participants changed).
- **Report events.** `ScoreService` dispatches `on_challonge_result_reported` (a captain reported, pending) and
  `on_challonge_result_applied` (written to Challonge) with the `ScoreReport` as subject.
- **Cheap state endpoint.** `GET /ccm/challonge_tournaments/state/{id}` returns the presenter data of a tournament
  from the local tables (no Challonge request). The overview block polls it (`refreshSeconds`) and reloads the
  page when `lastChangedAt` moved.
- **Request budget.** Free Challonge apps get 500 requests/month; a sync is 3 requests. The task `challonge_sync`
  only syncs tournaments due per `sync_interval_minutes`, pages only trigger a sync when data is older than
  `stale_after_minutes`. Any live layer must keep polling Challonge on this schedule — live-ness is about pushing
  *our* state to the browsers, not about asking Challonge more often.

## Plan

1. **Publisher.** A listener on `on_challonge_tournament_synced` and `on_challonge_result_applied` that publishes
   to the Mercure hub, the way `Shoutbox\Realtime\Realtime` does (own RFC 9068 JWT with `publisher_key`, topic
   `<canonical URL>/challonge/tournament/<id>`, plus `<canonical URL>/challonge/event/<eventID>` for the event
   pages). Payload: the diff plus the fresh presenter data (`Presenter::tournamentData()`), so browsers can render
   without a second request.
2. **Subscriber in the blocks.** `js/tournaments.js` subscribes via EventSource when `window.ChallongeLive` (hub URL,
   topics, cookie/token mode as in the shoutbox) is present, otherwise keeps polling. On a message: replace the
   polling reload with an in-place re-render (the Blackout templates would do this with a Vue component; the
   built-in UI can keep reloading).
3. **Widgets.** Live dashboard (`theme_blackout/themes/blackout/event_live.php`): a "matches live now" strip using
   `sections.live` across the event's tournaments, with a toast when a result lands. Big screen page type: a
   results ticker (last 5 completed matches, next 5 upcoming) and the champion when a tournament completes.
   Shoutbox: optional system shouts "Match F: Zürich Zephyrs 3:1 Basel Boosters" from `on_challonge_result_applied`.
4. **Sync cadence during a LAN.** Schedule `challonge_sync` every 2 minutes with `sync_interval_minutes = 2` for
   the event weekend (3 tournaments → ~4,300 requests/weekend: over the free limit, so a paid plan or a longer
   interval; with 10 minutes it is ~860).

## Ideas (not planned)

- Discord webhook on `on_challonge_result_applied`.
- Station support: Challonge "stations" (API: stations, station queuers) mapped to seat-map seats so the live page
  shows *where* a match is played.
