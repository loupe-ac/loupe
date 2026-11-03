# Loupe

A software factory for coding agents. Product design, tech design, code and
review, in one workflow.

[Website](https://loupe.ac) · [Documentation](https://loupe.ac/docs/) ·
[Interactive explainer](https://loupe.ac/landing/explainer.html)

https://github.com/user-attachments/assets/634599db-ccec-41b4-b221-8044b63ccc12

Coding agents scale faster than the person who supervises them. Each terminal
waits for an approval, a plan review or a conflict fix. Loupe is the control
plane for that loop. You move a card on a board. The workflow asks your agents
for the work, and it stops only where a person must decide.

Your agents run on your machine, on your own subscriptions. Loupe brings the
workflow.

<details>
<summary>What the video shows</summary>

The example project is Driftwood, a made-up cabin-rental site. Card 42 asks for
the nightly price on the search cards.

| Time | Scene |
|---|---|
| 0:10 | You drag the card from Backlog to Product design. |
| 0:14 | The bridge opens a Claude Code session. Claude offers two mockups on a Design canvas, reads your comment, and writes the product document. |
| 0:35 | You select a sentence in the document, comment on it, and request changes. |
| 0:53 | A worker answers the comment and posts version 2. You approve it. |
| 1:05 | A worker writes the tech design. After its approval, a worker builds the change and opens a pull request. |
| 1:14 | The Loupe agent review approves the pull request on GitHub. |
| 1:16 | You open the widget on the preview and leave a note on a price badge. A worker reads the note and fixes the badge. |
| 1:29 | The checks pass and the agent review approves the fix. Loupe merges the pull request and moves the card to Done. |

</details>

## The workflow

Each card moves through the same stages. Agents do the work at each stage.

| Stage | What happens | What you do |
|---|---|---|
| Product design | An agent drafts the product document and a clickable prototype, or you shape them together in a session. A second agent reviews the draft. | Review it before any code exists |
| Tech design | An agent writes the technical design from the approved product. A second agent reviews the draft. | Comment, then approve |
| Implementation | A worker builds the approved design and opens a pull request on GitHub. | Nothing |
| Agent review | A second agent reviews the change against the approved design. Fix rounds run until it passes. | Nothing |
| Live review | The change runs on a preview, with the review widget on the page. | Leave notes on the page, then approve |
| Done | The pull request merges and the card moves to Done. | Nothing |

When the GitHub App is on the repository and the card does not require your
review, the pull request approves itself. That happens after the agent review
passes and the checks are green.

## Features

| Feature | What it does |
|---|---|
| Board | Shows every card, with its stage and its state: working, waiting, stuck or needs you. Epics get their own lane. |
| Workflow rules | Decide when a card moves, which work it needs and where it waits for a person. |
| Interactive sessions | Let you take the wheel on any card. The card carries on from there. |
| Document review | Shows designs rendered. You comment on any line, suggest a rewrite, then approve. The agent revises with your notes in place. |
| Inbox | Collects questions, reviews and to-dos from agents in one list. A push notification tells you when a blocking question opens. |
| Agent review | Has a second agent review every design and every pull request before you see it. |
| Live review | Adds a widget to any preview of your app. You point at an element and leave a note, and the note lands on the card. An iOS app in the simulator works the same way. |
| Analytics | Shows cost per finished card, merge rate, fix rounds and hours to merge. Experiments compare models. |
| [Bridge](cli/README.md) | Runs on your machine as the `loupe` command. It takes the work that the workflow asks for, and it starts a worker for each piece. |
| MCP endpoint | Lets any Model Context Protocol (MCP) client read and write the board. An agent signs in through your browser, so nobody copies a credential by hand. |

## What you need

- Claude Code or Codex, on your own subscription or API key. Other models work
  through OpenRouter, with a Codex profile.
- A GitHub.com repository with the Loupe GitHub App. GitHub Enterprise and other
  forges are not supported yet.
- A Mac or Linux machine for the bridge. The bridge must run while work happens.

Preview deployments, a worktree script and a second GitHub account for your
agents are optional, and they make the workflow better.

## Get started

The hosted version at [loupe.ac](https://loupe.ac) is in private beta. Join the
waitlist on that page.

You can also host Loupe yourself for free. One
[Docker Compose file](docs/operating/install/docker-compose.md) runs the web
server, the worker and Postgres, and it needs no outside service.

Then connect a repository. [First steps](docs/getting-started/index.md) has the
full procedure:

```bash
brew install loupe-ac/tap/loupe
cd ~/code/my-project
loupe init --url https://your-loupe-instance.example.com
```

### Try it locally

One container holds the app, its worker and Postgres:

```bash
docker run --rm -p 127.0.0.1:8080:80 ghcr.io/loupe-ac/loupe:demo
```

Open <http://localhost:8080> and log in with `admin@example.com` and
`loupe-admin`. Bind to `127.0.0.1` as above, because this page publishes the
password.

This image is for evaluation only. Its `APP_SECRET` is a committed constant,
nothing terminates TLS, and it discards mail. [Demo](docs/operating/install/demo.md)
gives the volume and port options.

## Develop on it

```bash
just up                # start nginx, php-fpm, postgres
just composer install  # runs inside the php-fpm container
just migrate-run       # set up the database
just exec bin/console app:dev:seed   # log in as dev@loupe.test / password
```

`app:dev:seed` fills the database with one demo dataset, so every page shows
records. It also switches on the flags `inbox.enabled`, `artifacts.enabled` and
`billing.enabled`, and tags their rows `dev-seed`. Pass `--keep-flag-defaults` to
leave the existing flag values unchanged.

`just up` publishes no host port. It expects a reverse proxy on an external
Docker network named `traefik`, and it fails without one.
[`examples/`](examples/) holds a working proxy and a plain-HTTP alternative.
[Reverse proxy](docs/extending/reverse-proxy.md) explains the choice.

Loupe is built with Symfony, Tailwind CSS and Symfony UX (Stimulus and Turbo).
The bridge is written in Go.

## Documentation

The full documentation is at **[loupe.ac/docs](https://loupe.ac/docs/)**. It is
built from [`docs/`](docs/index.md) in this repository, so you can also read the
pages here on GitHub.

- [First steps](docs/getting-started/index.md) connects your coding agent and
  takes you through a first review.
- [Workflows](docs/using/workflows.md) explains how the rules move cards and ask
  for work.
- [Board](docs/using/board.md), [documents](docs/using/documents.md),
  [inbox](docs/using/inbox.md) and [live review](docs/using/live-review.md) cover
  daily use.
- [Run the bridge](docs/getting-started/run-the-bridge.md) and
  [Command-line bridge](docs/extending/cli-bridge.md) cover the workers.
- [Installing Loupe](docs/operating/install/index.md) gives the ways to run it,
  and helps you choose one.
- [Operating](docs/operating/first-run.md) covers first run, recovery and backups.
- [Environment variables](docs/reference/environment.md) and
  [console commands](docs/reference/commands.md) are the reference.
- [Troubleshooting](docs/troubleshooting.md) and
  [Known gaps](docs/known-gaps.md) list the known problems.

## Contributing

See [CONTRIBUTING](docs/contributing/index.md) and
[Development](docs/contributing/development.md). Report security issues
privately, as [SECURITY](docs/SECURITY.md) says.

## License

Copyright (C) 2026 Geoffrey Bachelet.

Licensed under the [GNU Affero General Public License v3.0 or later](LICENSE)
(AGPL-3.0-or-later). If you run a modified version of Loupe as a network service,
the AGPL requires you to make your source available to its users.

Every page has a "Source code" link in the footer to satisfy that requirement.
The link points at `APP_SOURCE_URL`, which defaults to this repository. That is
correct for an unmodified instance. If you change the code, set `APP_SOURCE_URL`
to the repository that holds your version.
