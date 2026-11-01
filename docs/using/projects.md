---
title: Projects
description: "Create projects and describe their purpose."
---

Each project belongs to one account, its owner. Loupe has no teams yet, so a project has no second member and you cannot share it with a group. Some pages are for the owner alone, such as the [Agent readiness tab and the Repositories section](#agent-readiness) of the project settings. Several agents can work for one owner. A coding agent signs in through the owner's account. See [First steps](../getting-started/index.md) and [Installing the CLI](../getting-started/cli.md).

Open All projects to choose a workspace. Each tile links to its Workshop and shows that project's counts.
Press Tab to reveal Skip to content. Press Enter to bypass navigation and focus the page content.
The next Tab moves to a control within that content.
The sidebar picker marks the current project with a check. It includes the current project even when newer projects fill the list.
Select another project to open its Workshop. Escape closes the picker and returns focus to Switch project.
A page outside a project, such as your account settings, keeps the last project you opened in the sidebar.
The sidebar marks the current page. The Documents count shows the documents that wait for a review, and archived documents are left out.
The Agents link shows the busy workers over the worker limit, such as 1/3.
Document, inbox and Activity counts remain visible beside their labels on the selected row.

Select New project, or anywhere on the dashed tile, to open the form in a dialog. The project switcher in the sidebar also has a New project entry, which opens the projects page with the dialog open. Enter a name. Optionally, add a description of up to 500 characters, a domain and a document language.
Descriptions appear as plain text on project tiles. Line breaks remain visible.
The form has no workflow field. Every new project gets the Lifecycle workflow, and the template creates its board columns.
The first-run wizard asks for a name, a description and a domain only. It takes the document language from the Accept-Language header of your browser, and you can change it in the project settings.
Select Add project to create it and open its Workshop.

The header keeps New project reachable on narrow screens and with enlarged text.

Use a tile's Edit control or Project settings to change its description.
Clear the description and save to remove it.
An agent connected to the project can change the name, description, domain and document language with the `project_update` MCP tool.
It changes the allowed origins of the sign-in widget with `project_origins_set`.
See [Changing the project settings](mcp.md#changing-the-project-settings).
An invalid submission keeps the entered text so you can correct it.

Project settings has no Inbox section.
The `inbox:` list of the workflow template decides which card waits open an item in the inbox.
See [Choosing which waits open an item](inbox.md#choosing-which-waits-open-an-item).

The first-project setup form also accepts a description.
Account data exports include descriptions in `projects.json`.

Open Agents to see the project's reported bridge connections, CLI versions, and latest heartbeats.
Connection details and setup buttons wrap to fit narrow screens and enlarged text.
Workshop's Your crew section shows these connections and their health. Select a row to open its connection details in Agents.
When the project has no reported connections, Your crew links to connection setup.
Each connection shows its own health. This does not report whether an individual agent is available or running.
Agent configuration and rules remain read-only in Loupe. Configure them through the CLI and its rule file.

## Create a project from the CLI

A signed-in `loupe` CLI creates a project with `POST /api/projects`. Send a JSON body with a `name`, an optional `domain`, an optional `origins` list and an optional `remote`, the git URL of the repository.
The old `website` field is gone. A request that sends it creates a project with no domain.
The `origins` list sets the allowed origins of the site-review widget. The call accepts what the allowed-origins setting accepts, and at most 20 origins after duplicates go. A bad origin gets `422` with a `violations` list, and Loupe creates no project.
A request with a `remote` and no `name` names the project after the repository. The new project gets the same board and workflow as a project made in the app.
The response is `201` with the `id`, the `slug`, the `name`, the `domain` and the `allowedOrigins`. A name that the owner already uses gets `409` with `name_taken` and the `slug` of the project that clashes. Use that slug with `loupe init --project <slug>`.
Only a token that carries the agent scope and covers every project of its owner can call it, as `loupe login` grants. Any other token, such as one bound to one project, gets `403` with `credential_not_owner_wide`. Each token can create 10 projects a minute.

## Agent readiness

Project settings has an Agent readiness tab. Only the project owner can open it.
The Discovery part shows the state of the latest discovery run, with the same status as the Run discovery row of the [readiness guide](workshop.md#run-discovery).
The Agent GitHub account part links to the page that sets up a [GitHub user for the agents](../getting-started/agent-github-account.md).
The Workshop guide part has one switch, "Show the readiness checklist".
Turn it on and select Save to show the [readiness guide](workshop.md#the-readiness-guide) on the Workshop again.
Turn it off and select Save to hide the guide.
While the guide is hidden, the tab shows the date it was hidden.
An agent connected to the project can show or hide the guide with the `readiness_guide_set` MCP tool.

## OpenRouter key

The project settings have an OpenRouter tab. Only the project owner sees it.
It shows the management key that Loupe uses to read the billed cost of the Codex runs of this project on OpenRouter.
The **Key in use** line says whether the key belongs to the project or to your account, and shows the end of the key and its state.

- **Use a key for this project** saves a key for this project only. It overrides your account key here.
- **Replace** saves another key. **Remove** deletes the project key, and the project uses your account key again.
- With no key anywhere, the tab offers a key for this project or a key for your account.

Loupe tests a key with OpenRouter before it saves the key.
[OpenRouter key](openrouter.md) explains the test, the states and the account key.

## Repositories

The project settings have a Repositories tab. Only the project owner sees it.
It lists the GitHub repositories of the project. The Connections tab holds the webhook and the GitHub App, which connect the repositories.
Connect a GitHub repository, and a merge, a review or a check result in it reaches the cards of this project that link the pull request.
[The board](board.md#what-github-tells-a-card) says what a card receives.

Loupe knows a repository by the ID GitHub gives it, so a rename or a transfer keeps it in the same project.
Through the GitHub App, a repository belongs to one project only. When the App of another project already holds a repository, Loupe does not connect it here.
A webhook feeds only the project it belongs to, and it blocks no other project.

Connections offers two methods. The operator of the instance decides which ones are available.

### A webhook for the project

Select Create a webhook. Loupe shows a payload URL and a secret.
Copy the secret at once. Loupe shows it one time only.
On GitHub, open the Settings of each repository, then Webhooks, then Add webhook. Paste the payload URL and the secret.
Set the content type to `application/json`.
Select "Let me select individual events", then select Pull requests, Pull request reviews, Check suites and Repositories.
A project has one webhook. Add the same URL and secret to each repository you want to connect.
A repository is connected when its first delivery arrives.

The webhook shows its own state. Waiting for the first delivery means GitHub has not sent anything yet.
Working shows the time of the last delivery.
Failing shows the time and the reason of the last refused delivery. A wrong secret or a content type other than JSON is the usual cause.
Select Generate a new secret to replace the secret. The old secret stops working at once, so paste the new one into each repository on GitHub.

### The GitHub App

Select Install on GitHub. On GitHub, choose the account and the repositories the App can reach.
GitHub then asks you to authorize Loupe. Loupe connects the installation only when GitHub lists it for your account.
The repositories of the installation that your GitHub account can reach join the list at once. Under "All repositories", each other repository joins on its first delivery.
When you add or remove a repository in the App settings on GitHub, the list follows.
The section says when GitHub suspends the installation or when someone uninstalls it.

The Connect page has a Git identity panel with the switch "Loupe manages the git identity of agents". The switch is on for every project. When it is on, agents push as the Loupe App, so you can approve their pull requests. Agents push with a token of the App that opens the repository of the project and no other. The panel says when agents cannot push, and names the missing installation or the missing write access. A machine whose bridge runs an older CLI keeps its own git setup, and the panel shows it as Not managed until you run `loupe update` there.

When you turn the switch off, agents use the git setup of each machine. A machine can also fall back to its own git setup while the switch is on. The panel then shows one line for each machine that runs a bridge for the project: the name its commits carry, and the GitHub account it pushes as. When a machine pushes as your own GitHub account, the panel warns you, because GitHub does not let you approve your own pull requests. Use a [separate GitHub account for agents](../getting-started/agent-github-account.md) on that machine.

### The list

The Repositories tab shows each repository with its state.
The state of a repository is one of these.
Waiting for the first delivery means Loupe has not received anything from the repository yet.
Working shows the time of the last delivery.
Quiet means no delivery arrived for 30 days. A quiet repository can be a repository with no activity, or a connection that stopped working on GitHub.

Each repository also shows the name of the workflow of the project, such as Lifecycle workflow.
Below it, Loupe lists what the workflow suggests for the repository, with one line for each finding.
A line is Blocking, Warning or Fits. A line says what Loupe read, such as the required checks or the merge method.
Loupe reads the settings with the permissions that the GitHub App has. It never changes a setting on GitHub.
Loupe reads again when you open the tab, unless the last read is less than 5 minutes old. While a read runs, the row says Checking…
The row shows the time of the last read, such as Checked 2m ago.
When a read fails, the row says so, and the lines keep the result of the last read that worked.
Loupe checks only repositories of the GitHub App. A repository that a webhook connects, with no App installation, gets no read. Its row shows a warning that says so, and the warning never pauses work.
The tab updates each row when a read ends. You do not need to reload it.

When a row has a gap, a box named "Fix it on GitHub" lists the steps to close it, with buttons that open the right GitHub page.
The first step can be an import of the Loupe ruleset. Select Download loupe-ruleset.json, then open Rules on GitHub and import the file.
After the import, open the ruleset and check that Loupe / Approval shows the Loupe App as its source. If it shows Any source, pick the Loupe App.
When an organization ruleset causes a gap, the step names the organization. Only an organization owner can change that ruleset.
Select Check again after a fix. Loupe reads GitHub at once, so the row shows the new result and a pause can end. Loupe allows 6 reads a minute for each repository. A later click shows the time of the last read.

Select Remove to disconnect a repository. Loupe stops updating cards from it.
With a webhook, or with an App installation on "All repositories", the next delivery from that repository connects it again. So also remove the webhook, or remove the repository from the App on GitHub.
With an App installation on selected repositories, Loupe ignores the repository until you remove it from the installation on GitHub and select it again.
