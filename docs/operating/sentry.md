---
title: "Sentry"
description: "What Loupe sends to Sentry, what it never sends, and how to turn it on."
---

Loupe can send errors, traces and profiles to [Sentry](https://sentry.io).
It is off until you set `SENTRY_DSN`. While that variable is empty, Loupe
samples nothing and sends nothing.

## Turn it on

Copy the DSN from the *Client Keys* page of your Sentry project, and set it in
`SENTRY_DSN`. Three variables control the integration:

| Variable | Default | Purpose |
|---|---|---|
| `SENTRY_DSN` | empty | Where events go. **Secret.** Empty turns Sentry off. |
| `SENTRY_TRACES_SAMPLE_RATE` | `0.25` | The share of web requests, worker messages and console commands that Sentry traces, from `0.0` to `1.0`. A card evaluation message has a fixed rate of `0.05`. |
| `SENTRY_PROFILES_SAMPLE_RATE` | `1.0` | The share of traces that Sentry also profiles, from `0.0` to `1.0`. |

[Environment variables](../reference/environment.md) lists them with the rest.

A malformed DSN does not stop the instance. The Sentry SDK ignores it and
writes a debug log line, so nothing goes to Sentry. The *Sentry* row on
`/admin/status`, and `bin/console health-check:status`, report a malformed DSN
as a failure. They check the DSN format only, and never call Sentry.

## Browser

Loupe can also load the Sentry browser SDK on every page, the admin area and
the error pages included. It is off until you
set `SENTRY_BROWSER_DSN`. These variables control it:

| Variable | Default | Purpose |
|---|---|---|
| `SENTRY_BROWSER_DSN` | empty | Where browser events go. It is not a secret, because each page that loads the SDK shows it. Empty loads no SDK. |
| `SENTRY_BROWSER_TRACES_SAMPLE_RATE` | `1.0` | The share of page loads that the browser SDK traces, from `0.0` to `1.0`. While `SENTRY_DSN` is set, a page load continues the server trace, and `SENTRY_TRACES_SAMPLE_RATE` decides for it. |
| `SENTRY_BROWSER_REPLAYS_SESSION_SAMPLE_RATE` | `0.0` | The share of page visits that the browser records as a full replay, from `0.0` to `1.0`. `0.0` records none. |
| `SENTRY_BROWSER_REPLAYS_ON_ERROR_SAMPLE_RATE` | `0.0` | The share of page visits whose last minute the browser sends as a replay when a JavaScript error happens, from `0.0` to `1.0`. `0.0` records none. |

The browser sends page loads, Web Vitals, interaction traces and JavaScript
errors, and replays when you turn them on. It posts them
directly to the ingest origin of the DSN, which Loupe adds to the
Content-Security-Policy `connect-src` list. Loupe scrubs each browser event
before it leaves the page. Every item in
[What Sentry never receives](#what-sentry-never-receives) stays true for the
browser: no URL or query string, no user content and no user identity.

### Interaction traces

Each sampled interaction starts its own trace, named after the route of the
page:

| Operation | Starts | Ends |
|---|---|---|
| `ui.turbo.submit` | A Turbo form submit starts. | The browser paints the response. A submit that redirects ends when the visit starts. |
| `navigation` | A Turbo visit starts. | The browser paints the new page. The span takes the route of the new page. |
| `ui.live` | A live change from the Mercure hub arrives. | The browser paints the change. Only the board, the card page and the decision summary record it. |

A submit or a visit sends its trace headers with its request, so the PHP
transaction joins the same trace. While the page load trace is still open, the
request joins the page load trace instead. A span with no end after 10 seconds ends
then, with the status `deadline_exceeded`. A Turbo stream or a frame that another
part of the page renders during a submit can end the submit span before its
own response paints.

Each span has two attributes:

| Attribute | Value |
|---|---|
| `loupe.tab_age_ms` | The time since the last full page load, in milliseconds. A Turbo visit keeps the page, so the value grows across visits. |
| `loupe.heap_mb` | The JavaScript heap in use, in megabytes. Only Chromium browsers report it, and Chromium can round it on a page that is not cross-origin isolated. |

These two values show whether a tab gets slower as it ages. The browser sends
no profile.

### Session replay

A replay is a recording of the page that Sentry plays back as a video. It is off
until you set one of the two replay rates above to more than `0.0`. A page then
loads a second Sentry file, `replay.min.js`. With both rates at `0.0` the page
does not load it, and nothing is recorded.

A replay shows the layout of the page, as it changed during the visit. A
person with access to your Sentry project can see how many elements a page
holds, which dialog opened, and where a person clicked. A replay is
behaviour data, so tell your users when you turn it on.

Loupe masks the page in the browser, before anything leaves it:

1. All text and all form input become grey blocks.
2. All images, video and other media are blocked.
3. Each element keeps only a short list of layout attributes, such as `class`,
   `type`, `role`, `width` and the `aria-` state attributes. Every other
   attribute is removed. This covers `href`, `src`, `value`, `id`, `title`,
   `aria-label` and every `data-` attribute, so no link target, CSRF token or
   element id leaves the page.
4. Every URL is cut to its origin. This covers the URL of the page and of
   each stylesheet.
5. CSS keeps no comment and no quoted text. A value that holds a word outside
   a list of CSS words is removed. A `style` attribute keeps only layout
   properties such as `top`, `left`, `width` and `transform`.
6. Navigation, fetch, XHR and console breadcrumbs are dropped, as for an error.

A replay does not record the text drawn by a CSS `content` rule. A browser
sends the recording uncompressed, because Loupe cannot clean a compressed
recording, so a replay uses more upload bandwidth than the same page without
one. If the scrub fails, the browser drops that part of the replay and sends
nothing unscrubbed.

The *Sentry in the browser* row on `/admin/status` reports a malformed
`SENTRY_BROWSER_DSN` as a failure, in the same way as the *Sentry* row.

## What Sentry receives

- A trace for each sampled web request, with a span for each Doctrine query and
  each outbound HTTP call. The health check and the bridge heartbeat get no
  trace, because they carry no performance signal. Their errors still reach Sentry.
- A trace for each sampled worker message, named after the message class.
- A trace for each sampled console command, named after the command.
  `messenger:consume` itself gets no trace, because each message it handles
  gets its own.
- A profile for each profiled trace, when the Excimer PHP extension is loaded.
  The production image ships Excimer. The development image does not, so a
  local instance with a DSN sends traces and no profiles. `/admin/status`
  warns when profiles are sampled and Excimer is missing.
- Each unhandled exception from a web request, a worker message or a console
  command, except a 404 or 405 response. An access denial on a web request
  with no signed-in user is also left out, because Loupe answers it with a
  redirect to the login page or a 401 response.
- The errors of scheduled tasks. Scheduled tasks send no traces.
- The queue metrics of [Queue metrics](#queue-metrics).

Twig and cache spans are off. A render or cache span between two queries hides
a run of repeated queries, and Sentry's N+1 query detector then misses it.

## Queue metrics

Once a minute, the worker sends two gauges for each message queue to Sentry. They let you graph a queue over time and alert on one that stops moving. With no usable `SENTRY_DSN`, the task does nothing. Run `bin/console app:report-queue-depth` to send the gauges once by hand and print them.

| Gauge | Meaning |
|---|---|
| `messenger.queue.pending` | The number of messages that wait for a worker. |
| `messenger.queue.oldest_age` | How long the oldest waiting message has waited, in seconds. |

A message waits when it is due and no worker holds a live claim on it. A claim stays live for one hour. This is the same rule as the worker check on `/admin/status`.

Each gauge has two attributes. `queue_name` is the queue in the database: `interactive`, `jobs`, `card_eval`, `card_sweep` and `failed`. Each queue has the name of its transport. `transport` is the name of the transport in `config/packages/messenger.yaml`. An empty queue reports `0` for both gauges, so a flat line means an empty queue and a blank line means the task did not run.

Create two metric monitors in Sentry once the first points arrive:

1. **Stuck queue.** Monitor `max(messenger.queue.oldest_age)` with the filter `queue_name:!failed`. Alert when it stays above 60 seconds for 5 minutes. The `failed` queue is left out, because its oldest age grows until somebody clears it.
2. **Failed messages.** Monitor `max(messenger.queue.pending)` with the filter `queue_name:failed`. Alert when it is above 0. [Failed messages](failed-messages.md) says how to retry or remove them.

## What Sentry never receives

- The email, IP address or id of a user. An email address in an exception
  message becomes `[email]`.
- A request body.
- A URL or a query string. The route name identifies the page.
- A request header other than `Host`, `User-Agent`, `Accept`,
  `Accept-Language`, `Content-Type` and `Content-Length`. Cookies, the
  `Authorization` header, the `Referer` header and every token header stay on
  the instance.
- A console command line.
- The arguments of a function in a stack trace.
- Trace headers. Loupe adds none to a request to another host, so other hosts
  learn nothing about the trace. The browser SDK adds them only to requests to
  the instance itself.

## Stay within a Sentry quota

Loupe traces a quarter of requests, messages and commands, and profiles each
traced one. It traces 1 in 20 card evaluations, because the workflow engine
evaluates a card each time its facts change. It sends no savepoint span.

On a busy instance this can still use up a Sentry quota. Lower
`SENTRY_TRACES_SAMPLE_RATE` first, for example to `0.1`. Lower
`SENTRY_PROFILES_SAMPLE_RATE` too if profiles still use too much. Errors are
not sampled, so each unhandled exception still reaches Sentry.
