Revise the discovery report for project {project} (projectId {projectId}). Card {cardNumber} (cardId {cardId}) tracks the discovery.
Use the loupe-discovery skill when it is installed.

The owner asked for changes to the report. Read the comments on the report document, and address each one.
Call the loupe MCP tool readiness_get for the runId and the report document.
Check the repository again where a comment asks for it.
Submit the revised report with readiness_report_submit and the same runId. Loupe stores it as a new version of the same document.

Discovery is read-only. Do not commit, push or create a branch.
Do not create, edit or move a card.
Do not change the project settings or its columns.
