# Epic branches and their children

Read this file when you work on an epic child, an `epic/<n>` branch or an epic
pull request. `SKILL.md` "A child of an epic targets the epic branch" keeps the
base branch rule and the seed rule of a Preview line.

## No ruleset covers `epic/*`

No ruleset covers `refs/heads/epic/*` since 2026-10-02. Loupe gives a pull
request into `epic/<n>` the required checks of `main`, and the merge stage
checks an epic child against them by name:

```bash
gh api repos/loupe-ac/loupe/rules/branches/main \
  -q '.[]|select(.type=="required_status_checks")|.parameters.required_status_checks[].context'
gh pr checks <n> --json name,bucket
```

Each name must have a check in the `pass` bucket. A pull request into `epic/*`
has no required checks on GitHub, so `gh pr checks --required` fails there.

A direct push to `epic/<n>` works, so the epic branch syncs by a merge of
`main`, and a fix round pushes to it. Never force-push it.

## How a child merges

An epic child is not a stacked pull request in the sense of `SKILL.md`. The
merge stage squash-merges it into `epic/<n>` with no approval, once its required
checks pass. No ruleset covers `epic/<n>`, so GitHub sends no behind event. The
stage compares the child with `epic/<n>`, and merges `epic/<n>` in when the
child is behind.

## The epic pull request

The epic pull request from `epic/<n>` into `main` needs a passed
`Loupe / Approval` check on its head, as any pull request into `main` does.
Loupe posts it when the agent review of the epic passes, or when a person
approves the head on GitHub. When the epic or a child has the **Requires human
review** switch on, only a person's approval posts it. The epic syncs by a merge
of `main`, through `gh pr update-branch` or the app sync. The sync makes a new
head, and Loupe posts the check again on it when the approval covers that
merge.

## The epic preview

The app opens the epic pull request as a draft after the first child merge.
While the epic sits in In review, an `epic-preview` work request on the epic
card has the merge stage refresh the epic preview. The preview is at
`https://epic-<n>.loupe.dev.localhost`, and the refresh runs once for each head
of the epic pull request. The stage carries the preview links of the children
to the `## Preview` section of the epic pull request. It re-mints each signed
link from its `to` and `email` parameters. It carries a plain signed-out link
by its path, and proves it on the epic preview with no cookie jar.
