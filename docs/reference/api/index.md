---
title: "API reference"
description: "The public HTTP API of Loupe at /api/v1: sign-in, requests, lists, retries, limits, errors and the version promise. Beta."
---

The public API lets a script, a CI job or another tool read and change what you
can read and change in Loupe. It covers projects, columns, cards, documents,
reviews, feedback, artifacts, inbox items, experiments, tags, series, metrics and analyses. The API is in **beta**.

The API operations section of this site lists each operation. Each instance also
serves the same description as an OpenAPI 3.0 file at `GET /api/v1/openapi.json`.
That route needs no sign-in.

## The beta promise

Version `v1` is in beta for 3 months from its first release. During the beta, a
field, a route or a refusal code can change, and the changelog says so. After
the beta, a change to `v1` never removes or renames a field, a route or a code.
A breaking change then goes into a new version, `v2`. Loupe keeps `v1` working
for at least 6 months after `v2` appears. A response of `v1` then carries the
`Deprecation` and `Sunset` headers, and the changelog names the end date. It
carries neither header today, because no `v2` exists.

`GET /api/versions` lists the versions that an instance serves and the range of
CLI versions that it supports. It needs no sign-in.

## Sign in

Send a bearer token in the `Authorization` header:

```bash
curl -H "Authorization: Bearer $LOUPE_API_KEY" https://app.example.com/api/v1/projects
```

The token is an [API key](../../using/api-keys.md), or the OAuth access token of a
signed-in [`loupe` CLI](../cli.md). An API key reaches the public API only. A
credential with no right to a route gets `403` with the code
`credential_not_allowed`. A missing, unknown or revoked token gets `401` with the
code `invalid_credentials`. An API key past its end date gets `401` with
`api_key_expired`.

The `loupe` CLI has a command for each operation, such as `loupe card get 42`.
It reads `LOUPE_API_KEY` for a key. [Commands for the public
API](../cli.md#commands-for-the-public-api) lists them.

A CLI that signed in before the public API existed has no `public` scope. Run
`loupe login` again to add it.

A request acts as the owner of the credential. The history of a card and a
review name an API key as the actor, such as "API key 'CI' (Ada)".

## Projects in a path

`{project}` in a path takes the id or the slug of a project. A slug changes when
the project is renamed. A script that must survive a rename uses the id. A card
goes by its number in the project, such as `/projects/loupe/cards/42`.

## Links to the app

Each project, card, document and artifact in an answer has a `url`. The `url` is
the full address of its page in the app, such as
`https://app.example.com/projects/<id>/board/cards/<id>`. A person who follows it
sees the resource.

1. The address starts with the public address of the instance, the `DEFAULT_URI`
   setting. It does not depend on the `Host` header of the request.
2. A card, a document and an artifact in a list, and in the short entries of a
   card, have their own `url`. The short entries are the documents, artifacts,
   related cards, parent and children.
3. The `url` of a document opens the latest version. The `url` of an artifact
   opens the newest version. `versionUrl` in the answer of a store opens the
   version that the call made.
4. A feedback note has a `url` that opens the Feedback tab of its card at the
   note. A note with no card has `null`. `pageUrl` is the page of the reviewed
   site where the reviewer left a web note. A native note has `null` in
   `pageUrl`.
5. A column has no `url`.
6. A write that answers with a short body, such as a hold, a publish, a verdict,
   a reply or a decision, holds the `url` of the card or the document that it
   changed.

## Requests

1. A request with a body sends `Content-Type: application/json`. Any other type
   gets `415` with the code `unsupported_media_type`. The artifact routes that
   store files take `multipart/form-data`.
2. Field names are camelCase.
3. `PATCH` changes only the fields that the body names.
4. A `POST` that creates answers `201`, with a URL in the `Location` header. The
   URL names the new resource, or the nearest resource that a route can read. A
   new artifact version names its artifact. A comment reply and a verdict name
   the review of the document.

## Lists

A list answers an object with `items` and `next`:

```json
{
  "items": [{ "number": 4, "title": "Proper HTTP API" }],
  "next": "https://app.example.com/api/v1/projects/loupe/cards?limit=50&cursor=eyJ2Ijoi..."
}
```

1. `next` is the URL of the next page, and it is `null` on the last page. The
   `Link` header carries the same URL with `rel="next"`.
2. `limit` is 1 to 200, and it defaults to 50.
3. A list has no total and no page numbers.
4. A cursor is opaque. It works only with the filters and the sort of the request
   that made it. A broken cursor, or a changed filter, gets `400` with the code
   `invalid_cursor`.

Each list has its own default order and its own `sort` values. A leading `-` sorts
newest first or highest first.

| List | Default order | `sort` values |
|---|---|---|
| Projects, artifacts, feedback | Creation order, oldest first | `createdAt`, `-createdAt` |
| Document versions | Version number, lowest first | `createdAt`, `-createdAt` |
| Documents | Creation order, oldest first | `createdAt`, `-createdAt` |
| Cards | Card number, lowest first, which is creation order | `number`, `-number`, `createdAt`, `-createdAt`, `updatedAt`, `-updatedAt` |
| Board columns | Board position | None |
| Card history | Newest first | None |

Two document filters change the default order, and a `sort` overrides both. `series`
reads the documents by their position in the series. `q` puts the documents with
every word in the title first, and each group reads oldest first. `q` does not
change the order of cards.

### Filter the cards by state

`GET /api/v1/projects/{project}/cards` takes two filters on the state of a card.
The state is the mark the board shows, and each row holds it in `state`.

1. `state` takes one kind: `stuck`, `needs-you`, `working` or `waiting`. Any
   other value, `chip` included, gets `422` with the error code `state_invalid`.
2. `stateCode` takes a code, such as `run-open` for a running worker. The codes
   belong to the workflow of the project, so the API does not list them. A code
   that no state has answers an empty list.
3. A card shows one state, the first of its reasons. The filters read that one
   state. A card that shows `stuck` is not in the list of `state=working`, even
   when a worker runs on it.
4. A card in a terminal column has no state. `status=done&state=working` answers
   an empty list, and no error.
5. A card that enters or leaves the state during a walk can be missed. This is
   true of `status` and `paused` too. No row repeats, and no error comes.
6. A server that does not have the filters ignores the parameters and answers
   every card. A script can check the `state` field of the rows.

```bash
curl -H "Authorization: Bearer $LOUPE_API_KEY" \
  "https://app.example.com/api/v1/projects/loupe/cards?state=working&fields=number,title,state"
```

## Choose the fields of a read

A read takes the `fields` query parameter. It names the fields of the answer, as a
comma list of top-level names. The 15 `GET` routes that return a project resource
take it. A write does not take it, and it answers the whole resource.

```text
GET /api/v1/projects/loupe/cards?fields=number,title,body
```

1. A request with no `fields` gets a light row on a list, and the whole resource on
   a single read.
2. A light row holds each plain field of the resource. It leaves out long text, such
   as the body of a card. It holds a short form for each linked item, such as a pull
   request with its URL and no check state.
3. A request with `fields` gets the named fields and no other field. Each named field
   comes back whole. A named `pullRequests` holds the check state and the review of
   each pull request. `url` is a field too, so `fields=title` leaves it out, and
   `fields=title,url` holds both.
4. `fields=all` returns the whole resource. It stands alone, so `fields=all,title`
   is refused.
5. The answer keeps the order of the resource. The order of the names in the request
   has no effect, and a name that appears twice counts once.
6. A name is a top-level field. A dotted name, such as `state.kind`, is refused.
7. A single read only removes fields. The route checks access before it reads
   `fields`.
8. The `next` URL of a list keeps `fields`. You can change `fields` between pages.

The document lists and the document version lists never read document text. They
refuse `fields=markdown` and `fields=all`. Read one document, or one version, to get
its `markdown`.

A bad `fields` value gets `422` with the code `validation_failed`. The `errors` list
has one entry for each bad name, with the field `fields`. The code is one of these:

| Code | Cause |
|---|---|
| `fields_invalid` | The value is empty, a name is empty or unknown, the value is a list such as `fields[]=a` and not one comma list, or `all` comes with another name. The message lists the allowed names. |
| `fields_refused` | The name is a field, but this list does not return it. A document list refuses `markdown` and `all`. |

The `full` parameter of the card list is gone. A request with `full=true` gets the
light row, as any other unknown query value does. Send `fields=all` to get whole
cards.

The OpenAPI document shows each shape that a read can answer. A list item is the
light row, the whole resource, or the named fields. The component of the named
fields has the prefix `Partial`, and it requires no field. A client that sends no
`fields` can rely on the light row on a list and on the whole resource on a single
read.

## Retry a create safely

A `POST` that creates takes an optional `Idempotency-Key` header of up to 255
characters. A key lasts 24 hours and belongs to one credential.

1. The same key with the same body repeats the first answer and creates nothing.
   This holds for every answer, a `4xx` or a `5xx` too.
2. The same key with another body gets `409` with the code
   `idempotency_key_reused`.
3. A key whose first request has not finished gets `409` with the code
   `idempotency_in_progress`. A first request that died before it answered gives
   the same answer, because the server cannot tell the two cases apart.
4. After a `5xx`, or after a long `409`, read the list to see whether the create
   landed. Use a new key only when it did not.
5. A `429` spends no key, so the same key is safe to send again.

## Limits

Each credential has a read budget and a write budget for each minute. The
defaults are 600 reads and 120 writes. An operator changes them with the
`api.rate_limit_reads_per_minute` and `api.rate_limit_writes_per_minute` flags on
the flags page of the [admin area](../../using/admin.md). A change needs no restart.

A request that signs in and reaches a public route gets these headers, also when
the route refuses it:

| Header | Meaning |
|---|---|
| `RateLimit-Limit` | The requests that the window allows |
| `RateLimit-Remaining` | The requests left in the window |
| `RateLimit-Reset` | The seconds until the window resets |

A request over the budget gets `429` with the code `rate_limited` and a
`Retry-After` header that holds the seconds to wait. The bridge and widget
routes keep their own limits, and send `Retry-After` only.

These answers carry no budget headers:

1. An answer to a request with no credential. `GET /api/v1/openapi.json` and
   `GET /api/versions` need no credential, and spend from a budget of the client
   address. A `429` on them still sends `Retry-After`.
2. A `401` or a `403` with the code `credential_not_allowed` from the sign-in
   check, because the request never reaches the budget.
3. A `404` with the code `route_not_found`, and a `405`, because no route matched.

The OpenAPI file lists these headers on the refusals that all routes share. Treat
them as optional on any refusal.

## Errors

Every error on `/api/v1` is `application/problem+json`, also for a path that
matches no route:

```json
{
  "type": "https://loupe.ac/docs/reference/api/errors#card_not_found",
  "title": "Card not found",
  "status": 404,
  "code": "card_not_found",
  "detail": "This project has no card with this number."
}
```

Test the `code`, and never the `detail`, because the wording can change. The
`type` links to the [error codes](errors.md) page. A validation refusal (`422`,
`validation_failed`) adds an `errors` list. Each entry has a `field`, a `code`
and a `message`.

## Start discovery

`POST /api/v1/projects/{project}/discovery` starts a discovery run. A run adds a
tooling card to Backlog. A bridge that serves the project then runs a read-only
worker, which checks the repository and writes a readiness report. The report
lists what remains and who acts: the owner or an agent. The `loupe discovery start`
command calls this route.

The request takes no body. The credential must manage the project. The answer is
`201`, with the card in the `Location` header:

```json
{
  "runId": "0198b0c2-6c1e-7d0a-9a8e-4b1f6d3e2a10",
  "cardId": "0198b0c2-6c3f-7b2e-8f41-9c0d5e7a1b22",
  "cardNumber": 12,
  "state": "requested",
  "url": "https://app.example.com/projects/<id>"
}
```

1. `url` is the Workshop page of the project. Its discovery row shows the run,
   and opens the report once the worker has written it.
2. A run needs a live bridge that can run discovery, a workflow on the project, and
   board automation switched on. Only one run can be open at a time.
3. A request that cannot start a run gets `409`. The code is `no_bridge`,
   `no_capable_bridge`, `bridge_paused`, `no_workflow`, `automation_off` or
   `discovery_running`. `discovery_running` adds `cardNumber`, the card of the
   open run. The `detail` says what to change.
4. The route takes the `Idempotency-Key` header, as the other creates do.

## Read how a GitHub repository stands

`GET /api/v1/projects/{project}/github/repositories/status?path=owner/name` tells
whether the Loupe GitHub App reaches one repository and whether the project holds
it. The `loupe github repository status` command calls this route. `loupe init` uses it.
Only the owner of the project may ask. The answer is `200`:

```json
{ "path": "acme/api", "status": "free" }
```

1. `in-project`: the project holds the repository through the App.
2. `free`: the App reaches the repository and no project holds it.
3. `other-project`: another project holds it. The answer names no project.
4. `not-shared`: the App does not reach the repository.
5. A `path` that is not `owner/name` gets `422`. A GitHub failure gets `502`,
   with the code `github_unavailable`.
6. Loupe caches the GitHub answer for ten minutes.

## Place a card in its column

`PATCH /api/v1/projects/{project}/cards/{number}` takes `position`, `beforeCard`
and `afterCard`. They place the card in the column it ends in, which is the
column of `status` when the call also sets one. `position` is a rank counted from
0, and a rank past the end puts the card last. `beforeCard` and `afterCard` name a
card of that column, by number or by id. A call passes at most one of the three.

`POST /api/v1/projects/{project}/cards/reorder` places a block of cards inside
one column in one write. The body holds `column`, the slug of an open column, and
`cards`, the cards of the block from top to bottom, each by number or by id. It
also holds exactly one of `at` (`top` or `bottom`), `beforeCard` and `afterCard`.
The other cards keep their order around the block. The answer is `200` with
`above`, `cards` and `below`. `cards` is the block with the `number`, `title` and
`rank` of each card. `above` and `below` are the cards next to the block, or null.

```bash
curl -X POST https://app.example.com/api/v1/projects/loupe/cards/reorder \
  -H "Authorization: Bearer $LOUPE_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"column": "next", "cards": [42, 17, 30], "at": "top"}'
```

A refusal is `422` `validation_failed`, and no card moves. The `errors[].code`
values are:

| Code | Cause |
|---|---|
| `place_conflict` | The call passes more than one place field. |
| `place_missing` | `card_reorder` passes none of `at`, `beforeCard` and `afterCard`. |
| `position_negative` | `position` is below 0. |
| `terminal_column` | The target column is terminal. It sorts by completion time. |
| `unknown_column` | The board has no column with the slug in `column`. |
| `unknown_status` | Another request deleted the column while the call ran. |
| `list_empty` | `cards` is empty. |
| `card_listed_twice` | A card appears twice in `cards`. |
| `card_unknown` | A reference names no card of the project. |
| `card_not_in_column` | A listed card is not in `column`. Move it with the update route first. |
| `neighbour_in_list` | `beforeCard` or `afterCard` names a card of `cards`. |
| `neighbour_not_in_column` | The neighbour is not in the column where the card ends. |
| `neighbour_is_card` | `beforeCard` or `afterCard` names the card being updated. |

A rank change writes no history row and starts no workflow work. The CLI
commands are `loupe card update` and `loupe card reorder`.

## Card values

A card holds one value for each key that its workflow template declares. The
`values` list of a card has an entry for each declared key, with `key`, `type`,
`visibility`, `value`, `writer` and `updatedAt`. `visibility` is `public` or
`internal`. A key with `public` visibility takes a write in `values` on
`PATCH /api/v1/projects/{project}/cards/{number}`. Only the workflow writes a key
with `internal` visibility, so the same write gets `422` with the field
`values.<key>` and the code `value_internal_key`. The list of declared keys in
the `value_undeclared_key` message leaves out internal keys.

## Inbox items

A script, such as a CI job, can put an item in the inbox of the owner of a
project. The routes work only while the inbox flag is on. With the flag off,
they answer `404`.

1. `POST /api/v1/projects/{project}/inbox/items` makes one item. `kind` is
   `question`, `todo` or `notice`. The item takes `title`, and optionally `body`,
   `options`, `multiple`, `freeText`, `cardIds` and `documentIds`, with the
   same rules as the `inbox_ask` MCP tool. The answer is `201` with the item and
   a `Location` header.
2. An item from the API never blocks, because nothing waits for its answer.
   `blocking: true` gets `422`. A non-blocking question about a card does not
   make the card wait for a person.
3. `dedupeKey` is a key of your choice, up to 200 characters. While an open
   item of the project holds the same key, the call answers `200` with that
   item and makes nothing. After the owner closes it, the next call makes a new
   item. A failing nightly job can send the same key each night, and the owner
   sees one item.
4. `GET /api/v1/projects/{project}/inbox/items/{itemId}` reads the item with
   the answer of the owner, the same fields as the `inbox_get` MCP tool. Poll
   it to learn the answer. `selectedOptions` holds the indexes of the options
   that the owner picked, and `note` holds a written answer.
5. The owner can dismiss every item from the API, a notice too.

An item from an API key has `origin: "api-key"`, and `apiKey` holds the id and
the name of the key. The inbox shows the name of the key as the sender. The
name stays when the key is removed later. An item from a signed-in CLI has
`origin: "agent"` and no key.

## Saved graphs

A saved graph is a tile on the Dashboard of the owner. The routes sit under
`/api/v1/projects/{project}/graphs`. They draw no chart. Read the data of a
graph with the metric query.

1. `GET .../graphs` lists the graphs in the order the Dashboard draws them. A
   graph answers its `id`, `name`, `position`, `width`, `showLegend`,
   `settings` and `formula`. `position` counts from 0 among the graphs on the
   Dashboard. `broken` names the part that the project no longer has, such as a
   bucket that no rule names. `removed=true` lists the graphs that were removed
   less than a day ago and that a restore can bring back. They answer
   `position: null` and an `undoUntil` time. The list takes `limit` and
   `cursor`, and walks in order of position, then id.
2. `POST .../graphs` saves a graph as the last tile and answers `201` with the
   graph and a `Location` header. Send `settings.unit` and either
   `settings.metric` with `settings.statistic`, or a formula with `rows`,
   `formula` and `format`. The other settings default as the
   `graph_save` MCP tool says. The Dashboard holds a limited number of
   graphs. A full Dashboard answers `409` `dashboard_full`.
3. `GET .../graphs/{graph}` reads one graph. A removed graph, a graph of
   another project and a missing graph answer `404` `graph_not_found`.
4. `PATCH .../graphs/{graph}` changes `name`, `settings`, `rows`, `formula`,
   `format`, `width`, `showLegend` or `position` in one transaction. A
   field you leave out keeps its value. `position` is the place to take among
   the graphs on the Dashboard, counted from 0. A place past the last puts the
   graph last. The settings you send join the settings the graph keeps, and the
   whole set must hold together. A setting that the metric or the range cannot
   take answers `422` with the values that are valid. A formula graph keeps
   its first row as `settings.metric` and `settings.statistic`. Send `rows` to
   change the rows, or `clearFormula: true` to go back to one metric.
5. `DELETE .../graphs/{graph}` removes the graph at once, with no confirmation
   step, and answers `200` with `undoUntil`.
6. `POST .../graphs/{graph}/restore` brings a removed graph back to its place.
   It answers `409` `restore_expired` after the day, and `409`
   `dashboard_full` when the Dashboard has no room. A graph that is already on
   the Dashboard stays as it is.

## Bucket rules

A time bucket rule sorts the tool calls of a run into a bucket. A tool call
takes the bucket of the first rule whose pattern matches it. A project holds at
most 50 rules.

1. `GET /api/v1/projects/{project}/bucket-rules` lists the rules in the order
   they apply. A rule answers `id`, `position`, `pattern` and `bucket`. The list
   takes `limit` and `cursor`.
2. `POST .../bucket-rules` adds a rule at the end and answers `201` with a
   `Location` header. `pattern` is a glob of at most 120 characters. `bucket`
   is 1 to 64 characters: lower case letters, digits, underscore and hyphen. A
   project with 50 rules answers `409` `bucket_rule_limit_reached`.
3. `PATCH .../bucket-rules/{rule}` moves the rule to `position`, counted from
   0. The other rules keep their order.
4. `DELETE .../bucket-rules/{rule}` deletes the rule and answers `200` with the
   `deleted` id.

A change to the rules computes the bucket times of the project again. A rule of
another project answers `404` `bucket_rule_not_found`.

## Analytics settings

`GET /api/v1/projects/{project}/analytics/settings` reads the analytics
settings. `defaultModel` and `defaultEffort` are the values the project sets,
or `null`. `model` and `effort` are the values a new analysis takes when it
names none.

`PATCH` on the same path changes `defaultModel`, `defaultEffort`,
`collectFullText` or `subcommandPrograms`. A field you leave out keeps its
value. `null` clears `defaultModel` or `defaultEffort`, so the instance default
applies. A list in `subcommandPrograms` replaces the stored list, and an empty
list clears it. A value that the settings refuse answers `422`.

## Experiments, tags and series

These routes read and curate what a project holds apart from its cards and
documents. Every list takes `limit` and `cursor`, as [Lists](#lists) says.

1. `GET /api/v1/projects/{project}/experiments` lists the experiments that the
   runs and the pins name, the latest run first. The experiments with no run
   come last. `archived=true` lists only the archived experiments, and the
   default lists only the others. A row holds `name`, `cards`, `lastRunAt` and
   `archived`.
2. `GET /api/v1/projects/{project}/experiments/{experiment}` reads the
   comparison that the Comparison tab shows: the `headline`, the `variants` and
   the `metrics`. The cards stay on `.../experiments/{experiment}/cards`. A money
   value is in US dollars, a duration of a built-in metric in milliseconds, and a
   duration of a metric from the workflow template in hours.
3. `PATCH /api/v1/projects/{project}/experiments/{experiment}` takes
   `{"archived": true}` or `{"archived": false}`. Archiving hides the experiment
   from the main list. It changes no pin and no variant. A run that Loupe
   records after the archive brings the experiment back. The answer is the row of
   the experiment. An unknown name answers `404` with `experiment_not_found`.
4. `GET /api/v1/projects/{project}/tags` lists the tags by name with the number
   of documents that carry each one. A tag that no document carries stays in the
   list with a count of `0`.
5. `GET /api/v1/projects/{project}/series` lists the series by name, ignoring
   case. A row holds `name`, `documentCount` and `highestOrdinal`, the highest
   position in use.
6. `PATCH /api/v1/projects/{project}/series/{series}` takes `{"name": "..."}`
   and renames the series. `{series}` is the current name, URL-encoded and matched
   ignoring case. Every document keeps its position. A name that another series
   holds answers `409` with `series_name_taken`.

A name in a path is the name as the runs or the documents write it. Encode a
`/` in a series name as `%2F`.

## Metrics and analyses

These routes read the numbers of the Analytics page. Each takes the read budget, and each list pages with a cursor.

1. `GET /api/v1/projects/{project}/metrics` lists the metrics by key. Each entry holds the units, the value type, the statistics and the groups the metric takes. The workflow template of the project can declare more metrics, and the list holds one entry for each bucket and each kind of harness event of the project.
2. `GET /api/v1/projects/{project}/metrics/query` reads one metric over time. `unit` is `run`, `card` or `reading`. Pass `metric` and `statistic`, and optionally `group`, `range`, `bucket` and `compare`. The values and defaults are those of the `metric_query` MCP tool.
3. To combine metrics, leave `metric` and `statistic` out. Pass `rows` as `letter:metric:statistic` items separated by commas, such as `A:cache-read-tokens:sum,B:cache-write-tokens:sum`, and pass `formula`, such as `A / B`. A metric key can hold a colon, as `bucket-time:tests` does. The letter is the text before the first colon, and the statistic is the text after the last one. `format` sets how the result reads: `auto`, `number`, `percent`, `money` or `duration`.
4. A combination that the metric refuses answers `422` with the field and the values to use. A series lists at most 100 rows, newest first, and `rowsTotal` counts them all.
5. The metric query has its own limit of 30 requests a minute for each credential. The answer to a request over it is `429` with `Retry-After`. The query reads every run of the project in its range, so keep a loop of queries to the ranges you need.
6. `GET /api/v1/projects/{project}/analyses` lists the analyses of the project, newest first. `GET /api/v1/projects/{project}/analyses/{analysis}` reads one. Both hold the topic, the scope, the state, the cost in US dollars and the proposals. An analysis of another project answers `404` with the code `analysis_not_found`.

The CLI has `loupe metric list`, `loupe metric query`, `loupe analysis list` and `loupe analysis get`. They need CLI 2.7.0 or later.

## Who made a change

A history event, a review and a decision name the actor. The `kind` is `human`,
`agent`, `reviewer`, `system` or `api-key`. For `api-key`, the answer holds the
id and the name of the key.

## Routes that are not public

The paths under `/api/v1/bridge` and `/api/v1/live-review-widget` serve the
`loupe` CLI and the site-review widget. They carry no promise, and no OpenAPI
file describes them for outside use. An API key does not reach them.

## See also

1. [API keys](../../using/api-keys.md) creates the credential for a script.
2. [Error codes](errors.md) lists every code of the public API.
3. [Connected apps](../../using/connected-apps.md) covers OAuth sign-ins.
