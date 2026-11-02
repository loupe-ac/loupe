---
title: "Connected apps"
description: "How an app connects to a Loupe account through OAuth, and how a user removes its access."
---

Loupe is an OAuth 2.1 authorization server. An app can ask a user for access,
and the user approves it on a consent page. The app then gets a short-lived
access token and a refresh token. Nobody copies a token by hand.

MCP and the CLI use this sign-in only. Loupe issues no static token for them, so
a machine where nobody can open a browser cannot reach `/mcp`. CI and scripts
that call the public API can use an [API key](api-keys.md) instead.

## The consent page

An app sends the user to `/oauth/authorize`. The user signs in first when
necessary. The consent page then shows three facts:

- The name of the app.
- The access the app asks for.
- The address that receives the answer.

An app can identify itself with a URL, such as Claude Code with
`https://claude.ai/oauth/claude-code-client-metadata`. What the consent page
shows then depends on whether the operator vouches for that exact URL, through
`OAUTH_TRUSTED_CLIENT_IDS`. See [Environment variables](../reference/environment.md).

- A client the operator vouches for shows its host alone, with the host's icon.
- Every other client shows its whole `client_id`, with the host in heavy type
  and the rest of the URL in light type, and with no icon. A document on a
  shared host, such as a CDN, is one of these: the host says nothing about who
  wrote the document.

The name an app gives itself always shows below that line. Loupe cannot check
that name, and the page says so for a client it does not vouch for.

When every address of the app is on the user's own computer, such as
`localhost` or `127.0.0.1`, the page shows a warning. Loupe cannot check which
app listens there, so approve only a connection that you started yourself.

An app asks for exactly one kind of access:

| Scope | Access | Project |
|---|---|---|
| `mcp` | The documents and live reviews of one project, through MCP. | The user picks one project that they own. |
| `site-review` | The live-review comments of one project. | The user picks one project that they own. |
| `agent` | The agent surface that the bridge uses. | None. |
| `public` | The public API, with all the rights of the user. | None. |

The user can pick only a project that they own. An app cannot choose the
project itself.

## Signing in the CLI with a code

`loupe login` uses the device flow (RFC 8628), because the CLI has no browser of
its own. It prints a link to `/oauth/device` and a code of eight letters, such
as `BCDF-GHJK`. Open the link in a browser where you are signed in to Loupe. The
page shows the app, the access and the code. Choose **Allow** only if you
started `loupe login` yourself and the code is the code in your terminal. The
CLI then gets its tokens and stores them.

The page also takes a code that you type at `/oauth/device`. Case, spaces and
dashes do not matter. A code expires after ten minutes, and it takes one answer
only. Each account can try 20 codes in 15 minutes.

Every instance registers the CLI as the public client `loupe-cli`, with the
scopes that `loupe login` asks for, which include `agent` and `public`. A
migration adds it, and it leaves an existing `loupe-cli` row as it is. The CLI appears on the *Connected apps* page as *Loupe CLI*.

## Connected apps in account settings

The *Connected apps* section of the account settings, at
`/account/connected-apps`, lists each app that holds access. Each row shows the
access and the project. **Revoke** removes the access of that app at once: its
access tokens and its refresh tokens stop working. To connect again, the app
must ask the user again.

The *Live review widget* row is always on the page. Under it, each live
preview token has a row with its card, its project, when it expires and when
the widget last used it. **Revoke** on a row ends that token only. **Revoke
all** ends every preview token and every widget sign-in of the account.

The *Overview* tab of a card page has a *Preview sign-in* panel. It shows your
live preview token for that card, when it expires and when the widget last
used it. **Revoke** there ends the token and returns you to the card. When the
card has more than one live token, the panel shows the label of each token
with its own **Revoke**.

An administrator sees the connected apps and the live preview tokens of a user
on the user page of the admin area. That view is read-only. The same page also
lists the [API keys](api-keys.md) of the user, and an administrator can revoke
a key there.

Loupe also removes access in these cases:

- The user deletes the project. Every grant for that project stops working.
- The user deletes the account. Loupe deletes every token of the account.
- An administrator suspends the user. The tokens stop working, and a refresh
  fails.

The data export includes `connected_apps.json`. API keys have their own file,
`api_keys.json`. It lists each connected app and
its access, then each live preview token with its project, card, label and
dates. A token entry carries the project name and the card title as well as
their ids. It contains no token value.

## Your GitHub account

The same page has a *GitHub account* section. It shows when the operator has
registered a GitHub App. See [Forge webhooks](../extending/forge-webhooks.md).
This connection is the other way round: Loupe acts as you on GitHub, and it
does not act as an app that uses Loupe.

- **Connect GitHub account** sends you to GitHub. Allow Loupe there. Loupe then
  shows your GitHub login and the time of the connection.
- Loupe keeps the tokens that GitHub gives it, encrypted. A token ends after
  eight hours, and Loupe refreshes it by itself.
- When GitHub refuses the refresh, the row shows that the connection expired.
  This happens when you remove Loupe on GitHub, or when the refresh token is
  six months old. Choose **Connect again** to fix it.
- **Disconnect** asks GitHub to void the token that Loupe holds, and then
  deletes the tokens. If GitHub does not confirm, Loupe still deletes the
  tokens and says so.
- Disconnect leaves Loupe in the authorized apps of your GitHub account,
  because another Loupe account can connect the same GitHub account. To end
  every access, remove Loupe under *Applications* in your GitHub settings.

Deleting your Loupe account deletes the stored tokens. GitHub keeps its grant
until you remove it in your GitHub settings. The data export includes
`github_user_connection.json` with your login and the dates of the connection.
It contains no token.

## For app developers

The discovery document is at `/.well-known/oauth-authorization-server`
(RFC 8414). The server supports these features:

- The authorization code grant with PKCE. Only the `S256` method is accepted,
  and a public client must send a code challenge.
- The device authorization grant (RFC 8628), at `/oauth/device-authorization`.
  Only a client that lists this grant can start a device flow, and it can ask
  only for a scope that needs no project.
- The refresh token grant. Each refresh returns a new refresh token, and the
  old one stops working once the client uses the new one. If the answer of a
  refresh is lost, the client can send the old token again. Loupe then issues a
  new pair and revokes the unused one. This works for 7 days after the first
  refresh. An operator sets the limit with `OAUTH_REFRESH_GRACE_SECONDS`, and
  can turn the behavior off with the `oauth.refresh_reuse.enabled` flag.
- The `iss` parameter on every redirect back to the app (RFC 9207).

Access tokens are JWTs that live for one hour. Send them in an
`Authorization: Bearer` header to `/mcp` or `/api`.

An operator registers each app with `league:oauth2-server:create-client`. See
[Commands](../reference/commands.md). Loupe has no dynamic client
registration.

An MCP client can skip the registration with a Client ID Metadata Document:

- The `client_id` is the https URL of a JSON document. The URL has a path, and
  no port, query, fragment or user name. Its host is a DNS name.
- The document's `client_id` equals that URL, and `token_endpoint_auth_method`
  is `none`. It lists from one to ten `redirect_uris`. Each is https, or http
  on `localhost`, `127.0.0.1` or `[::1]`.
- Loupe fetches the document when a user starts to connect the app. The
  document must be at most 5 KB of `application/json`, with no redirect. Loupe
  keeps it for its `Cache-Control` max-age, from five minutes to one day.
- Such an app can ask for the `mcp` scope only.

The `mcp` scope binds the token to the MCP endpoint (RFC 8707). Send
`resource=https://<host>/mcp` to `/oauth/authorize` and `/oauth/token`, or
leave it out. Any other value gets `invalid_target`. The token's `aud` claim is
that URI, and `/mcp` refuses a token for another audience. The protected
resource metadata is at `/.well-known/oauth-protected-resource/mcp` (RFC 9728).

An http redirect address on `localhost`, `127.0.0.1` or `[::1]` matches on any
port (RFC 8252). Every other address must match exactly.
