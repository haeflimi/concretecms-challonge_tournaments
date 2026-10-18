# Challonge API v2.1 — Developer Reference

Compiled 2026-10-18 from the official apidog pages (`https://challonge.apidog.io/*.md`).
Every page was downloaded verbatim as markdown/OpenAPI YAML; everything below is taken
from those pages. Where the pages are silent or contradictory the item is marked
**UNCLEAR:**. JSON examples marked *(constructed)* are built from the OpenAPI schema
defaults because the page itself ships no example payload.

Pages covered: Authorization, Error Responses, Resource Scoping, Tournament States,
Running a Two-Stage Tournament, Get/List Tournament, Change Tournament State,
List/Create/Bulk Create/Update/Delete/Clear Participants, List/Get/Update Match,
Change Match State, the models TournamentModel, ParticipantModel, ParticipantInput,
ParticipantBulkInput, ParticipantOutput, MatchModel, MatchInput, MatchOutput,
MatchStateInput, TournamentStateInput, plus Challonge Connect "Getting Started" and
`loadTheme`.

Not covered (exist on the site, not fetched): Create/Update/Delete Tournament, Match
Attachments, Stations, Station Queuers, Communities, the other JS API actions
(`scrollToCoordinates`, `scrollToMatchIdentifier`, `setZoomScale`, `zoomToCoordinate`,
`zoomToMatchIdentifier`), Game Integration (beta), Connect Request Signing.

---

## 1. Basics

### 1.1 Base URL and content negotiation

- Base URL: `https://api.challonge.com/v2.1`
- Every endpoint path in the OpenAPI spec ends in `.json`
  (e.g. `/tournaments/{tournament_id}.json`). The prose guides omit `.json`
  (e.g. `PUT /v2.1/tournaments/{tournament_id}/change_state`).
  **UNCLEAR:** whether the suffix is optional; the spec always uses it, so send it.
- Required headers on **every** request:

  | Header               | Value                                   | Notes |
  |----------------------|-----------------------------------------|-------|
  | `Content-Type`       | `application/vnd.api+json`              | required even on GET/DELETE per spec |
  | `Accept`             | `application/json`                      | |
  | `Authorization-Type` | `v1` or `v2`                            | `v1` = API v1 key, `v2` = OAuth2 bearer token |
  | `Authorization`      | `<api key>` (v1) / `Bearer <token>` (v2)| |
  | `If-None-Match`      | optional                                | "Used as caching key for future response" (GET endpoints only) |

- Wrong `Accept` → `406 Not Acceptable`; wrong `Content-Type` → `415 Unsupported Media Type`
  (both listed on every endpoint).
- Response bodies follow JSON:API: `{"data": {"id": "...", "type": "...", "attributes": {...}}}`
  for single resources and `{"data": [ ... ]}` for collections.
  **UNCLEAR:** the spec never shows `links`, `meta` or top-level `included`; the match
  schema puts `relationships` *inside* `attributes` (see §6.4), not beside it as JSON:API
  would.

### 1.2 Authorization

Two options:

1. **API v1 key** (own account only, or migration from v1).
   Issue the key at `https://challonge.com/settings/developer`.
   Headers: `Authorization-Type: v1`, `Authorization: <APIv1 key>`.
2. **OAuth2** (`Authorization-Type: v2`, `Authorization: Bearer <access_token>`).
   - Authorize URL: `https://api.challonge.com/oauth/authorize`
   - Token URL: `https://api.challonge.com/oauth/token`
   - Access tokens expire after **1 week** (`expires_in: 604800`); refresh tokens are
     issued and keep working (an old refresh token still works after a refresh).
   - Token response shape:
     ```json
     {
       "access_token": "accesstokenhere",
       "token_type": "Bearer",
       "expires_in": 604800,
       "refresh_token": "refreshtokenhere",
       "scope": "me tournaments:read tournaments:write matches:read matches:write participants:read participants:write",
       "created_at": 1623246724
     }
     ```
   - Refresh: `POST /oauth/token` form-encoded with
     `client_id, client_secret, grant_type=refresh_token, refresh_token, redirect_uri`.
   - Flows: `authorization_code` (web apps; `GET /oauth/authorize?client_id=…&redirect_uri=…&response_type=code&scope=me+tournaments:read+…`,
     then `POST /oauth/token` form-encoded with `client_id, client_secret, grant_type=authorization_code, code, redirect_uri`),
     `device_code` (device authorization grant for consoles/TVs), and
     **client credentials** (server-to-server; "closest match to V1 API keys"; the only
     way to get `application:manage` and application-scoped routes such as
     `/application/tournaments.json`).
   - Scopes: `me`, `tournaments:read`, `tournaments:write`, `matches:read`,
     `matches:write`, `attachments:read`, `attachments:write`, `participants:read`,
     `participants:write`, `communities:manage`, `application:organizer`,
     `application:player`, `application:manage` (client credentials only).
   - The spec also lists an `API Key` security scheme as query param `api_key`, but
     no operation references it. **UNCLEAR:** whether `?api_key=` works on v2.1.

### 1.3 Resource scoping

Three scopes; all non-OAuth endpoints are affected:

| Scope | Path | Notes |
|-------|------|-------|
| **User** (default) | `/v2.1/tournaments…` | Resources owned by the authorized user (= API key owner without OAuth). Community-owned tournaments are **not** included by default; add `include_community_tournaments=1` to include community tournaments *that the user created*. |
| **Community** | `/v2.1/communities/{community}/tournaments…` or alternate `/v2.1/tournaments…?community_id={community}` | `{community}` = community subdomain or permalink (example `fauxthrone`). OAuth token needs `communities:manage` and the user must tick the community when authorizing. Every tournament/participant/match endpoint documents the `community_id` query param as "**must** include … if the tournament belongs to a community". |
| **Application** | `/v2.1/application/tournaments…` | Resources associated with your developer application. Tournaments created via this prefix are auto-linked to the app; tournaments created manually on challonge.com can be linked to the app in the UI. Requires client credentials (`application:manage`) or `application:organizer`/`application:player` user tokens. |

**UNCLEAR:** whether a v1 API key can access community tournaments at all (the
community-scope text only mentions OAuth scope `communities:manage`). For a v1-key client,
try `?community_id=<subdomain>` / the `/communities/…` prefix and watch for 403/404.

### 1.4 Pagination

Collection endpoints (List Tournaments, List Participants, List Matches) use plain
query params, **not** JSON:API `page[number]`/`page[size]`:

| Param      | Type    | Default | Meaning |
|------------|---------|---------|---------|
| `page`     | integer | `1`     | "Number of the page" |
| `per_page` | integer | `25`    | "Number of collection members per page" |

**UNCLEAR:** maximum `per_page`, and whether the response carries any pagination
metadata (`meta`/`links`/total count headers). The spec shows only `{"data": [...]}`.
Implementation: loop until a page returns fewer than `per_page` items (or zero).

There is **no** `include`, `sort`, `fields` or `filter[...]` query param documented on
any of the fetched endpoints.

### 1.5 Error responses

HTTP codes (from the Error Responses guide):

| Code | Meaning |
|------|---------|
| 200 | OK — retrieving and updating a resource |
| 201 | Created — "Creating a resource uses 201" (guide). **UNCLEAR:** the Create/Bulk Create Participant specs document `200` as the success code, not 201. Accept both. |
| 204 | No Content — deleting a resource |
| 400 | Bad Request — missing params, invalid payload format, validation/processing errors |
| 401 | Unauthorized — not authenticated / access token not passed properly |
| 403 | Forbidden — authenticated but resource not allowed (missing permissions) |
| 404 | Not Found — resource or endpoint does not exist |
| 406 | Not Acceptable (wrong `Accept`) |
| 415 | Unsupported Media Type (wrong `Content-Type`) |
| 422 | Unprocessable Entity — "the updated resource was unable to be processed" |
| 500 | Internal Server Error |

Standard error body (guide example):

```json
{
  "errors": [
    {
      "status": 422,
      "detail": "Tournament Format is invalid",
      "source": { "pointer": "/data/attributes/tournament_format" }
    }
  ]
}
```

- `status` integer — HTTP code, "usually 400 or 422".
- `detail` string — developer-friendly message, usable for end users.
- `source` object with `pointer` — present only if the error originated in the JSON
  payload; **absent** for query/path-param errors (e.g. invalid `:id`).
- Multiple errors are returned together when several validations fail.

**UNCLEAR (schema inconsistency):** the tournament endpoints (Get/List/Change State)
declare the error body as the `ErrorResponse` object above (`{"errors": [...]}`), while
*all* participant and match endpoints declare it as a **bare array** of `ErrorModel`
(`[{"detail","status","source"}]`). A robust client must accept both: if the decoded
JSON is a list, treat it as the `errors` array; if it is an object, read `errors`.

---

## 2. Tournament states and transitions

### 2.1 `state` values (Tournament States guide)

Before start:
- `pending` — still adding participants, changing format, rules etc.
- `checking_in` — check-in option enabled; participants may check in
- `checked_in` — check-in results have been processed
- `accepting_predictions` — bracket prediction option enabled; people may submit predictions

Started:
- `group_stages_underway` — 2-stage: group stage being played
- `group_stages_finalized` — 2-stage: group stage done, final stage not started
- `underway` — final stage underway
- `awaiting_review` — all results reported; can be reviewed/edited

Ended:
- `complete`

**UNCLEAR:** the tournament `state` attribute is **not** part of the `Tournament`
schema in the OpenAPI spec (see §5), although the guide says "each tournament has a
`state` attribute". Expect it in real responses but do not rely on the documented schema.

### 2.2 Change Tournament State — `PUT /tournaments/{tournament_id}/change_state.json`

Request body (`TournamentStateInput`):

```json
{
  "data": {
    "type": "TournamentState",
    "attributes": { "state": "start" }
  }
}
```

`data.attributes.state` (required), enum:

| Value                  | Effect (from guides) |
|------------------------|----------------------|
| `process_checkin`      | ends check-in: `checking_in` → `checked_in` |
| `abort_checkin`        | aborts check-in (back to `pending`) |
| `start_group_stage`    | 2-stage only: starts the tournament, state → `group_stages_underway` |
| `finalize_group_stage` | 2-stage only: run once all group matches complete, → `group_stages_finalized` |
| `reset_group_stage`    | 2-stage only: undo the group stage |
| `start`                | starts the (final) bracket → `underway` |
| `finalize`             | from `awaiting_review` → `complete`; assigns `final_rank` |
| `reset`                | resets the bracket (back to `pending`) |
| `open_predictions`     | → `accepting_predictions` |

Observed transition rules from the guides: after the last match is reported the state
moves to `awaiting_review` **automatically**; `finalize` is then required to reach
`complete` and get `final_rank`. Illegal transitions return `422`.
**UNCLEAR:** the exact allowed-from states for `reset`, `reset_group_stage`,
`abort_checkin`, `open_predictions` are not documented.

Responses: `200` → `{"data": TournamentModel}`; errors `401 404 406 415 422` (`ErrorResponse` object).

### 2.3 Two-stage tournament runbook (guide "Running a Two-Stage Tournament")

1. `POST /v2.1/tournaments` with
   ```json
   {"data":{"type":"Tournaments","attributes":{
     "name":"Test tournament","tournament_type":"single elimination",
     "match_options":{"consolation_matches_target_rank":3},
     "group_stage_enabled":true,
     "group_stage_options":{"stage_type":"round robin","group_size":4,"participant_count_to_advance_per_group":2}}}}
   ```
2. Add participants individually or via `bulk_add` (see §4).
3. `PUT …/change_state` with `start_group_stage` ("For a group stage tournament, this starts the tournament").
4. `GET …/matches?state=open` — group-stage matches carry a `group_id`.
5. Report each match with `PUT …/matches/{match_id}` (see §6.3); example from the guide
   reports a 3-1 win and marks the winner with `"advancing": true`.
6. `PUT …/change_state` with `finalize_group_stage` once all group matches are complete.
7. `PUT …/change_state` with `start` → final stage.
8. `GET …/matches?state=open` — final-stage matches have `group_id: null`.
9. Report final matches the same way.
10. When all matches are complete the state becomes `awaiting_review` automatically;
    `PUT …/change_state` with `finalize` to reach `complete`.
11. `GET …/participants` — `final_rank` "will be assigned for all final stage
    participants"; group-stage-only participants do **not** get a `final_rank`
    ("Let us know if you need this set for group stage participants").

Note the guide's `type` values differ from the spec defaults: guide uses
`"Tournaments"`, `"Participants"`, `"Match"`; spec defaults are `"tournament"`,
`"participant"`, `"match"`, `"Participants"` (bulk), `"TournamentState"`, `"MatchState"`.
**UNCLEAR:** whether `data.type` is validated at all. Use the spec defaults
(`participant`, `match`, `Participants`, `TournamentState`, `MatchState`).

---

## 3. Tournament endpoints

### 3.1 List Tournaments — `GET /tournaments.json`

"Get a list of tournaments organized by the owner of the API key or OAuth grantor."

Query params:

| Param | Type | Values / default | Meaning |
|-------|------|------------------|---------|
| `community_id` | string | subdomain or permalink | community scope (or use `/communities/{id}/` prefix) |
| `page` | integer | `1` | page number |
| `per_page` | integer | `25` | page size |
| `state` | string | `pending`, `in_progress`, `ended` | filter (note: these are *filter groups*, not the `state` attribute values) |
| `type` | string | `single_elimination`, `double_elimination`, `round_robin`, `swiss`, `free_for_all`, `time_trail`, `grand_prix` | "when group stage is enabled, this is for the final stage" (underscored here, space-separated in the attribute) |
| `created_after` | string | `mm/dd/yyyy` | |
| `created_before` | string | `mm/dd/yyyy` | |
| `include_community_tournaments` | `1` | from Resource Scoping guide | include community tournaments the user created |

Headers: the four standard ones + optional `If-None-Match`.

Response `200` ("Tournament Response"):

```json
{ "data": [ { "id": "30201", "type": "tournament", "attributes": { ...Tournament, see §5... } } ] }
```

Errors: `401`, `406`, `415` (`ErrorResponse` object).

### 3.2 Get Tournament — `GET /tournaments/{tournament_id}.json`

- `tournament_id` (path, string, required): "ID (recommended) or URL of the Tournament".
- Query: `community_id`. Headers: standard + `If-None-Match`.
- `200` → `{"data": TournamentModel}`; errors `401 404 406 415` (`ErrorResponse` object).

Example `200` *(constructed from schema defaults)*:

```json
{
  "data": {
    "id": "30201",
    "type": "tournament",
    "attributes": {
      "name": "Rainbow Six: Siege Tournament",
      "url": "r6siege_tournament_xx",
      "tournament_type": "single elimination",
      "game_name": "Tom Clancy's Rainbow Six Siege",
      "private": false,
      "starts_at": "2025-01-01T18:00:00Z",
      "description": "",
      "notifications": { "upon_matches_open": true, "upon_tournament_ends": true },
      "match_options": { "consolation_matches_target_rank": 3, "accept_attachments": false },
      "registration_options": { "open_signup": false, "signup_cap": null, "check_in_duration": null },
      "seeding_options": { "hide_seeds": false, "sequential_pairings": false },
      "station_options": { "auto_assign": false, "only_start_matches_with_assigned_stations": false },
      "group_stage_enabled": false,
      "group_stage_options": {
        "stage_type": "round robin", "group_size": 4, "participant_count_to_advance_per_group": 2,
        "rr_iterations": 1, "ranked_by": "", "rr_pts_for_match_win": 1, "rr_pts_for_match_tie": 0.5,
        "rr_pts_for_game_win": 0, "rr_pts_for_game_tie": 0, "split_participants": false, "tie_breaks": []
      },
      "double_elimination_options": { "split_participants": false, "grand_finals_modifier": "" },
      "round_robin_options": { "iterations": 2, "ranking": "", "pts_for_game_win": 1, "pts_for_game_tie": 0, "pts_for_match_win": 1, "pts_for_match_tie": 0.5 },
      "swiss_options": { "rounds": 2, "pts_for_game_win": 1, "pts_for_game_tie": 0, "pts_for_match_win": 1, "pts_for_match_tie": 0.5 },
      "free_for_all_options": { "max_participants": 4 },
      "tie_breaks": []
    }
  }
}
```

### 3.3 Change Tournament State — see §2.2.

---

## 4. Participant endpoints

All under `/tournaments/{tournament_id}/participants…`, `tournament_id` = "ID (recommended) or URL".
All accept `community_id` query param. Required OAuth scope: `participants:read` / `participants:write`.

### 4.1 List Participants — `GET /tournaments/{tournament_id}/participants.json`

- Query: `community_id`, `page` (default 1), `per_page` (default 25). No other filters.
- Headers: standard + `If-None-Match`.
- `200` → `{"data": [ParticipantModel, …]}`; errors `401 406 415` (bare array of ErrorModel).

Example `200` *(constructed)*:

```json
{
  "data": [
    {
      "id": "76",
      "type": "participant",
      "attributes": {
        "name": "Player 1's Name",
        "seed": 1,
        "group_id": null,
        "tournament_id": 21,
        "username": null,
        "final_rank": null,
        "states": { "active": true },
        "misc": "Store whatever string you like here",
        "timestamps": { "created_at": "2023-04-21T14:29:06.374Z", "updated_at": "2023-04-21T14:29:06.374Z" }
      }
    }
  ]
}
```

### 4.2 Create Participant — `POST /tournaments/{tournament_id}/participants.json`

"Creates a participant. If an email or username is set, a new or existing Challonge
user is invited."

Body (`ParticipantInput`):

```json
{
  "data": {
    "type": "participant",
    "attributes": {
      "name": "AllBossFights",
      "seed": 1,
      "misc": "",
      "email": "me@allbossfights.xyz",
      "username": "AllBossFights"
    }
  }
}
```

Only `name` is required. Minimal body (from guide): `{"data":{"type":"Participants","attributes":{"name":"Player 1"}}}`.

- `200` (spec; guide says creates use 201) → `{"data": ParticipantModel}`.
- Errors `400 401 406 415` (bare array of ErrorModel). Validation errors come as `400`.

### 4.3 Bulk Create Participants — `POST /tournaments/{tournament_id}/participants/bulk_add.json`

Body (`ParticipantBulkInput`): `data.attributes.participants` is an array of
`Participant` objects, **minItems 1, maxItems 20** → chunk larger lists into batches of 20.

```json
{
  "data": {
    "type": "Participants",
    "attributes": {
      "participants": [
        { "name": "Player 2", "seed": 1, "misc": "cem-user-42", "email": "p2@example.com", "username": "player2" },
        { "name": "Player 3" }
      ]
    }
  }
}
```

Each item: `name` (string, required), `seed` (integer), `misc` (string, "API-only field
for your foreign key or other use"), `email` (string), `username` (string).

- `200` → `{"data": [ParticipantModel, …]}` (array).
- Errors `400 401 406 415` (bare array of ErrorModel).
- **UNCLEAR:** whether a validation failure in one item rejects the whole batch, and
  what `source.pointer` looks like for item N.

### 4.4 Update Participant — `PUT /tournaments/{tournament_id}/participants/{participant_id}.json`

- Body: same `ParticipantInput` as create (`name` required by schema even on update —
  **UNCLEAR** whether partial updates without `name` are accepted; send the full set).
- `200` → `{"data": ParticipantModel}`; errors `401 404 406 415 422` (bare array).

### 4.5 Delete Participant — `DELETE /tournaments/{tournament_id}/participants/{participant_id}.json`

Two success codes:
- `200` "API Response when the participant is **deactivated**" → `{"data": ParticipantModel}`
  (presumably when the tournament has started; `states.active` becomes false).
- `204` "API Response when the participant is **deleted**" → empty/string body.
- Errors `401 404 406 415` (bare array).

### 4.6 Clear All Participants — `DELETE /tournaments/{tournament_id}/participants/clear.json`

- No body. `204` on success (empty body). Errors `401 406 415 422` (bare array);
  `422` presumably when the tournament has already started (**UNCLEAR**).

---

## 5. Tournament model (`TournamentModel` / `Tournament`)

`TournamentModel`: `id` (string, e.g. `"30201"`), `type` (`"tournament"`), `attributes` (`Tournament`).

`Tournament` attributes (required: `name`, `tournament_type`):

| Attribute | Type | Default | Meaning |
|-----------|------|---------|---------|
| `name` | string | — | required |
| `url` | string | — | URL slug, e.g. `r6siege_tournament_xx` (→ `https://challonge.com/<url>`) |
| `tournament_type` | string enum | `single elimination` | `single elimination`, `double elimination`, `round robin`, `swiss`, `free for all` (spaces!) |
| `game_name` | string | — | |
| `private` | boolean | `false` | opted out of search engines and hidden from Challonge indexes |
| `starts_at` | string (date_time) | — | scheduled start; Challonge will **not** auto-start |
| `description` | string | — | |
| `notifications.upon_matches_open` | boolean | `true` | |
| `notifications.upon_tournament_ends` | boolean | `true` | |
| `match_options.consolation_matches_target_rank` | integer | `3` | SE/DE only; consolation matches up to this placement |
| `match_options.accept_attachments` | boolean | `false` | allow match attachment uploads |
| `registration_options.open_signup` | boolean | `false` | Challonge users may self-register |
| `registration_options.signup_cap` | integer | — | max participants before waitlist |
| `registration_options.check_in_duration` | integer | — | minutes of check-in before start; multiple of 5 |
| `seeding_options.hide_seeds` | boolean | `false` | |
| `seeding_options.sequential_pairings` | boolean | `false` | ignore seeding, place top to bottom |
| `station_options.auto_assign` | boolean | `false` | requires ≥1 station |
| `station_options.only_start_matches_with_assigned_stations` | boolean | `false` | |
| `group_stage_enabled` | boolean | `false` | two-stage format; requires `start_group_stage`/`finalize_group_stage` transitions |
| `group_stage_options.stage_type` | enum | `round robin` | `round robin`, `single elimination`, `double elimination` |
| `group_stage_options.group_size` | integer | `4` | |
| `group_stage_options.participant_count_to_advance_per_group` | integer | `2` | |
| `group_stage_options.rr_iterations` | integer | `1` | 1 single, 2 double, 3 triple RR |
| `group_stage_options.ranked_by` | enum | `""` | `""`, `match wins`, `game wins`, `game win percentage`, `points scored`, `points difference`, `custom` |
| `group_stage_options.rr_pts_for_match_win` | float | `1` | for `custom` ranking |
| `group_stage_options.rr_pts_for_match_tie` | float | `0.5` | |
| `group_stage_options.rr_pts_for_game_win` | float | `0` | |
| `group_stage_options.rr_pts_for_game_tie` | float | `0` | |
| `group_stage_options.split_participants` | boolean | `false` | DE only: bottom half of seeds start in losers bracket |
| `group_stage_options.tie_breaks` | string[] | — | max 3, priority ordered |
| `double_elimination_options.split_participants` | boolean | `false` | |
| `double_elimination_options.grand_finals_modifier` | enum | `""` | `""` (LB winner must win twice), `skip`, `single match` |
| `round_robin_options.iterations` | integer | `2` | |
| `round_robin_options.ranking` | enum | `""` | same values as `ranked_by` |
| `round_robin_options.pts_for_game_win` | float | `1` | |
| `round_robin_options.pts_for_game_tie` | float | `0` | |
| `round_robin_options.pts_for_match_win` | float | `1` | |
| `round_robin_options.pts_for_match_tie` | float | `0.5` | |
| `swiss_options.rounds` | integer | `2` | |
| `swiss_options.pts_for_game_win` / `pts_for_game_tie` / `pts_for_match_win` / `pts_for_match_tie` | float | `1`/`0`/`1`/`0.5` | |
| `free_for_all_options.max_participants` | integer | `4` | |
| `tie_breaks` | string[] | — | RR/Swiss, max 3: `match wins`, `game wins`, `game win percentage`, `game w/l difference`, `points scored`, `points difference`, `match wins vs tied`, `median buchholz` (Swiss only) |

**UNCLEAR / NOT IN SCHEMA:** the documented `Tournament` schema is an *input-style*
schema. It contains **no** `state`, `full_challonge_url`, `live_image_url`,
`sign_up_url`, `participants_count`, `progress_meter`, `started_at`, `completed_at`,
`created_at`/`updated_at`, `id`-as-attribute, `subdomain`, `game_id`, or
`timestamps`. The Tournament States guide confirms `state` exists at runtime. Treat
any of these as optional/unknown when parsing and verify against a live response.

---

## 6. Match endpoints and model

All under `/tournaments/{tournament_id}/matches…`; `community_id` query param applies.
OAuth scopes `matches:read` / `matches:write`.

### 6.1 List Matches — `GET /tournaments/{tournament_id}/matches.json`

Query params:

| Param | Type | Values / default | Meaning |
|-------|------|------------------|---------|
| `community_id` | string | | |
| `page` | integer | `1` | |
| `per_page` | integer | `25` | |
| `state` | string | `pending`, `open`, `complete` | "State of the Matches" |
| `participant_id` | string | | "Filter matches by Participant Id" |

Headers: standard + `If-None-Match`.
`200` "Tournament Matches Response" → `{"data": [MatchModel, …]}`; errors `401 406 415` (bare array).

### 6.2 Get Match — `GET /tournaments/{tournament_id}/matches/{match_id}.json`

`200` → `{"data": MatchModel}`; errors `401 404 406 415` (bare array).

### 6.3 Update Match (report scores) — `PUT /tournaments/{tournament_id}/matches/{match_id}.json`

"Updates a match with scores." Body (`MatchInput`):

| Field | Type | Required | Default | Meaning |
|-------|------|----------|---------|---------|
| `data.type` | string | | `match` | guide uses `"Match"` |
| `data.attributes.match` | array of `Match` | | — | **minItems 1, maxItems 8** (one entry per participant) |
| `data.attributes.tie` | boolean | no | `false` | "To mark the match complete as a tie, set this to true and ensure `advancing` is false for reported scores." |
| `data.attributes.location` | string | no | — | free text; use Stations endpoints for structure |
| `data.attributes.scheduled_time` | string | no | — | "Date time string for scheduled start time" |

`Match` item (required: `participant_id`, `score_set`):

| Field | Type | Default | Meaning |
|-------|------|---------|---------|
| `participant_id` | **string** | `"76"` | participant id (string, even though ids are numeric) |
| `score_set` | string | `"4,2,4"` | "Comma-separated scores for this participant_id. E.g. if participant ID 76 won 4-1, 2-4, 4-0, the value should be '4,2,4' (the other player's value should be '1,4,0'). This format may seem odd, but it scales to support 3+ participants per match in Free For All." |
| `rank` | integer | `1` | participant's rank in the match (FFA) |
| `advancing` | boolean | `false` | marks the participant that advances/wins |

**How the winner is set:** there is **no** `winner_id` in the request. The guide's
example marks the winner with `"advancing": true` on that participant's entry:

```json
{
  "data": {
    "type": "match",
    "attributes": {
      "match": [
        { "participant_id": "215353979", "score_set": "3", "advancing": true },
        { "participant_id": "215353980", "score_set": "1" }
      ]
    }
  }
}
```

Best-of-3 example (sets 16-14, 10-16, 16-9; participant 76 wins):

```json
{
  "data": {
    "type": "match",
    "attributes": {
      "match": [
        { "participant_id": "76", "score_set": "16,10,16", "advancing": true },
        { "participant_id": "75", "score_set": "14,16,9" }
      ]
    }
  }
}
```

Tie: `"tie": true` and **no** entry has `advancing: true`.

**UNCLEAR:**
- Whether `advancing: true` is mandatory to complete the match or whether Challonge
  infers the winner from scores when omitted (the spec default is `false` for all,
  the guide explicitly sets it on the winner → always set it).
- Whether partially reporting (scores without a winner, leaving the match `open`) is
  possible via this endpoint.
- Whether `rank` matters outside Free For All.
- Whether `location` / `scheduled_time` can be sent without `match` (schema does not
  mark `match` required at the attribute level, but it has `minItems: 1`).

Responses: `200` → `{"data": MatchModel}` with updated `state`, `scores`,
`score_in_sets`, `points_by_participant`, `winner_id`; errors `401 404 406 415 422`
(bare array of ErrorModel).

### 6.4 Match model (`MatchModel` / `MatchOutput`)

`MatchModel`: `id` (string, e.g. `"8008135"`), `type` (`"match"`), `attributes` (`MatchOutput`).

| Attribute | Type | Example | Meaning |
|-----------|------|---------|---------|
| `state` | enum | `complete` | `pending` (prerequisite matches not done), `open` (playable), `complete` |
| `round` | integer | `1` | round number (negative rounds are losers bracket in DE — **UNCLEAR**, not documented) |
| `identifier` | string | `"A"` | bracket letter label |
| `suggested_play_order` | integer | `1` | |
| `scores` | string | `"2 - 0"` | human-readable summary (sets/points won per side) |
| `score_in_sets` | "object" (really array of `[int,int]`) | `[[3,1],[4,2]]` | per set `[player1Score, player2Score]` |
| `points_by_participant` | "object" (really array) | `[{"participant_id":355,"scores":[3,4]},{"participant_id":354,"scores":[1,2]}]` | per participant: integer id + per-set scores |
| `timestamps.created_at` | string | `2023-04-21T14:29:06.374Z` | |
| `timestamps.updated_at` | string | | |
| `winner_id` | integer | `355` | "The participant ID of the winner" |
| `relationships.player1.data.{id,type}` | string / `"participant"` | `"355"` | player 1 (note: nested **inside** `attributes`) |
| `relationships.player2.data.{id,type}` | string / `"participant"` | `"354"` | player 2 |

Example (Get Match `200`, constructed from defaults):

```json
{
  "data": {
    "id": "8008135",
    "type": "match",
    "attributes": {
      "state": "complete",
      "round": 1,
      "identifier": "A",
      "suggested_play_order": 1,
      "scores": "2 - 0",
      "score_in_sets": [[3, 1], [4, 2]],
      "points_by_participant": [
        { "participant_id": 355, "scores": [3, 4] },
        { "participant_id": 354, "scores": [1, 2] }
      ],
      "timestamps": { "created_at": "2023-04-21T14:29:06.374Z", "updated_at": "2023-04-21T14:29:06.374Z" },
      "winner_id": 355,
      "relationships": {
        "player1": { "data": { "id": "355", "type": "participant" } },
        "player2": { "data": { "id": "354", "type": "participant" } }
      }
    }
  }
}
```

**UNCLEAR / NOT IN SCHEMA:** `loser_id`, `underway_at`, `group_id`, `scheduled_time`,
`location`, `station_id`, `prerequisite_match_ids`, `has_attachment`, `started_at`,
`completed_at`, `tournament_id`, `forfeited`, `optional`, `scores_csv` are **not** in
the documented `MatchOutput` schema. The two-stage guide explicitly states that
"matches will have null values for `group_id`" in the final stage, so `group_id` **is**
present on real responses (and the `ParticipantOutput` has `group_id` too). Parse all
of these defensively (`$attrs['group_id'] ?? null`). For unplayed matches
`winner_id` is presumably `null`, `scores` presumably `"0 - 0"` or empty; `player1`/
`player2` `data.id` may be `null` while the match is `pending` (not documented).
The `scores_csv` field of API v1 does not exist; v2.1 uses `scores` (string),
`score_in_sets` and `points_by_participant`.

### 6.5 Change Match State — `PUT /tournaments/{tournament_id}/matches/{match_id}/change_state.json`

Body (`MatchStateInput`):

```json
{
  "data": {
    "type": "MatchState",
    "attributes": { "state": "mark_as_underway" }
  }
}
```

`state` (required), enum:

| Value | Effect |
|-------|--------|
| `reopen` | reopen a `complete` match (→ `open`), e.g. to correct a result |
| `mark_as_underway` | flag the match as currently being played (sets the "underway" indicator in the bracket) |
| `unmark_as_underway` | clear the underway flag |

Note: "underway" is **not** a `state` enum value; `state` stays `open`. The
documented `MatchOutput` has no `underway_at` field — **UNCLEAR** how the underway flag
is surfaced in responses (v1 had `underway_at`); verify live.

Responses: `200` "Match Response" → `{"data": MatchModel}`; errors `401 404 406 415 422`
(bare array of ErrorModel). The spec lists the request content type as
`application/json` for this one endpoint (all others `application/vnd.api+json`) —
keep sending `application/vnd.api+json`; **UNCLEAR** whether either is enforced.

---

## 7. Participant model (`ParticipantModel` / `ParticipantOutput`)

`ParticipantModel`: `id` (string, e.g. `"76"`), `type` (`"participant"`), `attributes` (`ParticipantOutput`).

| Attribute | Type | Meaning |
|-----------|------|---------|
| `name` | string | display name |
| `seed` | integer | seed (1-based) |
| `group_id` | integer / null | "If this is a group stage match, there will be a group id here." — set while in group stage, null otherwise |
| `tournament_id` | integer | |
| `username` | string / null | "The participant's associated Challonge username, if present." |
| `final_rank` | integer / null | set after `finalize` for final-stage participants only |
| `states.active` | boolean | false after deactivation via DELETE on a started tournament |
| `misc` | string | "API-only field for your own foreign key or other use" — store the local user/registration id here |
| `timestamps.created_at` | string | ISO 8601 |
| `timestamps.updated_at` | string | |

Input (`ParticipantInput` → `Participant`): `name` (required), `seed`, `misc`, `email`,
`username`. Setting `email` or `username` invites a Challonge user.

**UNCLEAR / NOT IN SCHEMA:** `group_player_ids`, `checked_in`, `checked_in_at`,
`invitation_id`, `email`, `display_name`, `attached_participatable_portrait_url`,
`on_waiting_list`, `removable`, `participatable_or_invitation_attached` from API v1 are
**not** documented in v2.1 `ParticipantOutput`. In particular there is no documented
mapping from a participant to its *group-stage player id*; the two-stage guide says
group matches carry `group_id` and `ParticipantOutput.group_id` names the group. The
match `relationships.player1/player2.data.id` in group-stage matches may be the
participant id or a group-player id — verify live before relying on it.

---

## 8. Bracket embed (Challonge Connect / JavaScript API)

### 8.1 iframe module URL

From the Getting Started page (the embed code for `challonge.com/single_elim`):

```html
<iframe src="https://challonge.com/single_elim/module" width="100%" height="500"
        frameborder="0" scrolling="auto" allowtransparency="true"></iframe>
```

Format: `https://challonge.com/<tournament url>/module`. For community/subdomain
tournaments the host is presumably `https://<subdomain>.challonge.com/<url>/module`
(**UNCLEAR**, not on the fetched pages).

**UNCLEAR:** the fetched pages document **no** query parameters for `/module`. The
classic Challonge embed generator offers `theme`, `multiplier`, `match_width_multiplier`,
`show_final_results`, `show_standings`, `scale_to_fit`, `show_tournament_name`,
`show_live_status`, `tab` etc., but none of these are documented on the apidog site —
verify by opening e.g. `https://challonge.com/single_elim/module?theme=2&scale_to_fit=1`
in a browser before relying on them.

### 8.2 postMessage control (JS API)

"The Challonge JavaScript API allows you to control embedded Challonge brackets from
client-side browsers and react to events triggered by them." Send commands from the
parent window:

```js
challongeIframeElement.contentWindow.postMessage(
  {
    ChallongeJSAPIAction: action, // e.g. "setZoomScale"
    parameters: { /* params for the action */ },
  },
  "*" // use "https://challonge.com" (or the sandbox URL) instead of "*" to secure this
);
```

Example from the page:

```js
const challongeWin = document.getElementById('challonge-iframe').contentWindow;
challongeWin.postMessage(
  {
    ChallongeJSAPIAction: "scrollToCoordinates",
    parameters: { coordinates: { x: 1, y: 1 }, animationDuration: 500 },
  },
  "*"
);
```

For debugging, `window.ChallongeJSAPI` is available when viewing a bracket directly on
challonge.com, e.g. `ChallongeJSAPI.setZoomScale(1.5)`.

Documented actions (names from the site index; only `loadTheme` was fetched):
`scrollToCoordinates`, `scrollToMatchIdentifier`, `setZoomScale`, `zoomToCoordinate`,
`zoomToMatchIdentifier`, `loadTheme`.

### 8.3 `loadTheme(theme)`

"Load a theme by name that defines the vector graphics and placements."

| parameter | type | description |
|-----------|------|-------------|
| `theme` | String | name of the theme. Publicly available for testing: `"challonge"`, `"xsplit"`, `"streamcontrol"` |

Via postMessage: `{ ChallongeJSAPIAction: "loadTheme", parameters: { theme: "challonge" } }`
(**UNCLEAR:** the exact parameter key; the page only gives the function signature
`loadTheme(theme)`, so `theme` is the natural key).

**UNCLEAR:** the pages say the API lets you "react to events triggered by" the bracket
but document no event message format (no `message` listener payloads).

---

## 9. Quick cheat sheet for the PHP client

```
GET    /tournaments.json?page=1&per_page=25[&state=in_progress][&type=single_elimination][&community_id=…]
GET    /tournaments/{id}.json
PUT    /tournaments/{id}/change_state.json          {"data":{"type":"TournamentState","attributes":{"state":"start"}}}
GET    /tournaments/{id}/participants.json?page=&per_page=
POST   /tournaments/{id}/participants.json          {"data":{"type":"participant","attributes":{"name":…,"seed":…,"misc":…}}}
POST   /tournaments/{id}/participants/bulk_add.json {"data":{"type":"Participants","attributes":{"participants":[{…}×≤20]}}}
PUT    /tournaments/{id}/participants/{pid}.json    (same body as POST)
DELETE /tournaments/{id}/participants/{pid}.json    → 204 deleted | 200 deactivated
DELETE /tournaments/{id}/participants/clear.json    → 204
GET    /tournaments/{id}/matches.json?state=open[&participant_id=][&page=&per_page=]
GET    /tournaments/{id}/matches/{mid}.json
PUT    /tournaments/{id}/matches/{mid}.json         {"data":{"type":"match","attributes":{"match":[{"participant_id":"76","score_set":"16,10,16","advancing":true},{"participant_id":"75","score_set":"14,16,9"}],"tie":false}}}
PUT    /tournaments/{id}/matches/{mid}/change_state.json {"data":{"type":"MatchState","attributes":{"state":"reopen|mark_as_underway|unmark_as_underway"}}}
```

Community scope: prefix with `/communities/{subdomain}` or append `?community_id={subdomain}`.
Headers always: `Content-Type: application/vnd.api+json`, `Accept: application/json`,
`Authorization-Type: v1`, `Authorization: <key>`.
Errors: JSON is either `{"errors":[…]}` or a bare `[…]`; each item `{status, detail, source?: {pointer}}`.
