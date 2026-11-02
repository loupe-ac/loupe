---
title: "API reference"
description: "The public HTTP API of Loupe at /api/v1: sign-in, requests, lists, retries, limits, errors and the version promise. Beta."
---

The public API lets a script, a CI job or another tool read and change what you
can read and change in Loupe. It covers projects, columns, cards, documents,
reviews, feedback and artifacts. The API is in **beta**.

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

A CLI that signed in before the public API existed has no `public` scope. Run
`loupe login` again to add it.

A request acts as the owner of the credential. The history of a card and a
review name an API key as the actor, such as "API key 'CI' (Ada)".

## Projects in a path

`{project}` in a path takes the id or the slug of a project. A slug changes when
the project is renamed. A script that must survive a rename uses the id. A card
goes by its number in the project, such as `/projects/loupe/cards/42`.

## Requests

1. A request with a body sends `Content-Type: application/json`. Any other type
   gets `415` with the code `unsupported_media_type`. The artifact routes that
   store files take `multipart/form-data`.
2. Field names are camelCase.
3. `PATCH` changes only the fields that the body names.
4. A `POST` that creates answers `201`, with the URL of the new resource in the
   `Location` header.

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
4. The order is creation order, oldest first. Cards use their number.
   `sort=updatedAt` exists on the card list only.
5. A cursor is opaque. It works only with the filters and the sort of the request
   that made it. A broken cursor, or a changed filter, gets `400` with the code
   `invalid_cursor`.

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

Every response of a public route carries these headers:

| Header | Meaning |
|---|---|
| `RateLimit-Limit` | The requests that the window allows |
| `RateLimit-Remaining` | The requests left in the window |
| `RateLimit-Reset` | The seconds until the window resets |

A request over the budget gets `429` with the code `rate_limited` and a
`Retry-After` header that holds the seconds to wait. The bridge and widget
routes keep their own limits, and send `Retry-After` only.

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
