---
title: "What runs in production"
description: "The processes a production instance needs, and why the worker is not optional. Applies to every topology."
---


Loupe runs as **two processes from the same image**, plus Postgres and,
optionally, a Mercure hub.

| Process | What it is |
|---|---|
| **Web** | `docker/prod/Dockerfile`, running supervisord as PID 1: `php-fpm` + `nginx`, and nothing else. No background process runs here. Listens on port 80. |
| **Worker** | The *same image*, started with a different command. Deliberately **not** a supervisord program inside the web container, so worker restarts never recycle php-fpm and nginx. It consumes `scheduler_default` first, then `jobs` — a deep jobs backlog must not delay schedule ticks. It is also the only thing that runs scheduled work: **everything recurring rides its schedule**, including the hourly `app:purge-expired-exports`, so without a worker expired archives are never purged and nothing periodic happens at all. |
| **Postgres** | Any Postgres the app can reach. It also carries the message queue: `MESSENGER_TRANSPORT_DSN` defaults to `doctrine://default?auto_setup=0`, so there is no broker to run. Sessions and the application cache live here too (`sessions`, `cache_items`), so **web replicas share both** — no sticky sessions to configure, no session loss on deploy, and the per-IP rate limits are counted once across the fleet rather than once per replica. |
| **Object storage** | Only needed when `EXPORT_STORAGE=s3`. Any S3-compatible bucket; required whenever the web and worker processes do not share a filesystem, or data-export downloads 404. |
| **Mercure hub** | Needed for live-review push and for live updates in the browser: the board, the worker run lists and the inbox count. Without it, each page shows changes by others on its next load. Optional, and off until `MERCURE_JWT_SECRET` is set. In-memory, so delivery is best effort. See "Known gaps". |

## The worker is not optional

Nothing consumes the queues unless you run it, and nothing warns you: queued
mail is never delivered, data exports never build, expired export archives are
never purged, the trial-end sweep never runs, and the live-review outbox never
drains. Every one of those fails silently — the request that queued the work
still returns 200.

```
php bin/console messenger:consume scheduler_default interactive card_eval jobs --time-limit=3600 --memory-limit=128M
php bin/console messenger:consume card_sweep --time-limit=3600 --memory-limit=128M
```

Run both consumers. `scheduler_default` is listed **before** `interactive`, `card_eval` and `jobs`
deliberately: a deep jobs backlog must not delay schedule ticks. `interactive` holds
the messages that one action of a person starts and that the person waits on, such as
the close of an inbox ask after an approval. It is listed before `card_eval`, so a burst
of card evaluations never delays them. A worker that never reads `interactive` leaves
those messages never run. `card_sweep`
holds the workflow sweep, which checks every open card every 10 minutes. It has
its own consumer so the sweep never delays a job that a person waits for. A
single command that reads `scheduler_default interactive card_eval jobs card_sweep`, last, also works,
but then the sweep waits while `jobs` stays busy. A worker that never reads
`card_sweep` never runs the sweep, and nothing warns you. `--time-limit` recycles
the process hourly and `--memory-limit` guards against a leak in a long-lived
consumer.

Both shipped topologies run `supervisord -c /etc/worker-supervisord.conf`, which
starts both consumers — `worker_command` in `terraform/main.tf`, the `worker`
service in `docker/compose/prod.yaml`. If you deploy some other way, this is the
piece it is easiest to forget.

