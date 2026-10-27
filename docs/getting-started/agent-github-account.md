---
title: "Git identity of agents"
description: "Who pushes the code of your agents, how Loupe manages it by default, and how to give the agents a GitHub user of their own."
---

GitHub does not let a user approve their own pull request. If your agents push
as your GitHub account, you cannot approve their work. Loupe avoids this by
default. It manages the git identity of the agents, so they push as the Loupe
GitHub App and not as you.

## The default: Loupe manages the identity

Each project has the setting "Loupe manages the git identity of agents". The
setting is on for every project.

When the setting is on, a bridge worker pushes as the bot of the GitHub App of
the project. The bridge asks Loupe for a short-lived token of the App each time
a worker starts. The token opens the repository of the project and no other
repository, and GitHub ends it after one hour. The worker has no `GH_TOKEN` or
`GITHUB_TOKEN`. Git and `gh` get the token from the `loupe credential` command.

The App must be installed for the repository of the project, with write access
to Contents and Pull requests. See [The GitHub App](../using/projects.md#the-github-app)
for the install steps.

The Repositories tab of the project settings shows whether each repository fits
the workflow of the project. It also writes the steps to fix a repository, such as
the ruleset that makes `Loupe / Approval` gate a merge. See
[Repositories](../using/projects.md#repositories).

## The Git identity panel

The Connect page of the project has a Git identity panel. Open **Project
settings**, then **Connections**. Only a member who can manage the connections of
the project sees the panel.

The panel shows the switch "Loupe manages the git identity of agents". Below the
switch, it shows one line for each running machine. A line names the machine and
tells you who the machine pushes as. For example, "Pushes as the Loupe app" or
"Pushes as acme-agent, the agent account set on this machine".

A machine whose bridge runs an older CLI keeps its own git setup. Its line says
"Not managed" until you run `loupe update` on that machine.

### Warning about your own account

Loupe compares the push login of a machine with the GitHub account that you
connected. When both match, the panel warns you:

> This machine pushes as your GitHub account. You cannot approve its pull requests.

When Loupe cannot tell whose account the login is, the warning says:

> This machine pushes as acme-dev. If this is your own account, you cannot approve its pull requests.

A machine gets this warning when it does not use the managed identity, and its
git setup pushes as your account.

## When agents cannot push

Loupe cannot give a token when the App is not ready. The panel then shows
"Agents of this project cannot push", with the cause:

- The operator of the instance has not finished the setup of the GitHub App.
- The App is not installed for the repository of the project. The panel has an
  **Install the GitHub App** button.
- The installation is suspended. Unsuspend it on GitHub.
- The installation lacks write access. The panel names the missing permissions.
  Grant them on GitHub.

The panel also has a **Turn this off** button, which sets the project to use the
git setup of each machine. See the next section.

## Turn the setting off

Clear the switch "Loupe manages the git identity of agents" on the Connect page.
The panel then says "Agents use the git setup of each machine."

With the setting off, the bridge leaves git alone. Workers push with the git
setup of the machine, and that can be your own account. The panel shows the
commit name and the push login that each machine reports, and it warns you when
a machine pushes as your account. Loupe does not give out App tokens for the
project while the setting is off.

## Give the agents an account of their own

A machine can push as a separate GitHub user instead of the App bot. This is an
optional override for one machine.

Loupe does not create the account, and it never sees its token. The token stays
on the machine of the bridge. The bridge sends only the login to Loupe.

1. Create a separate GitHub user for the agents. Do not use your own account,
   or the account that installed the GitHub App.
2. Invite that user to the repository with write access. Sign in as that user
   and accept the invitation.
3. As that user, create a token. Use a fine-grained token with **Contents** and
   **Pull requests** set to read and write on the repository. A classic token
   with the `repo` scope also works.
4. On the machine of the bridge, run `loupe agent-account set`. Paste the token
   and press Enter. You can also pipe the token in. The command asks GitHub
   which user owns the token, and stores the token, the login and the user id.
   GitHub refuses a bad token, and the command then stores nothing.
5. Restart the bridge. The bridge reads the account when it starts.

The workers of that machine then push, commit and call `gh` as the agent user.
Git records the author and the committer with the GitHub noreply address of that
user, `<id>+<login>@users.noreply.github.com`. For example, the user
`acme-agent` with the id `4242` commits as
`4242+acme-agent@users.noreply.github.com`. The panel shows that machine as
"Pushes as acme-agent, the agent account set on this machine". An agent account
takes priority over the managed identity on its machine.

### The optional readiness row

The [readiness guide](../using/workshop.md#the-readiness-guide) has an optional
row, Who pushes the code, under Ready for pull requests. The count of required
steps leaves it out. The row is done when you record the login of the agent
account and a running bridge pushes as that login.

To record the login, select **Set up** on the row. The project owner can also
open the page from **Project settings**, then **Agent readiness**. Loupe refuses
a login that is not a valid GitHub login. It also refuses the login of the
account that installed the GitHub App on the project. An empty login clears the
recorded one.

## Remove the account

Run `loupe agent-account clear` on the machine of the bridge, then restart the
bridge. The workers then use the managed identity again, or the git setup of
the machine when the setting is off. The bridge sends an empty login, and Loupe
clears the push login of that bridge. To clear the login that the project
records, save an empty login on the agent account page.

The [bridge reference](../extending/cli-bridge.md#credentials-for-workers)
describes how workers get their credentials.
