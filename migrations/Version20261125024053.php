<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125024053 extends AbstractMigration
{
    private const array PROMPTS = [
        'discovery' => <<<'PROMPT'
            Run discovery {subjectId} of project {project} (projectId {projectId}).
            Use the loupe-discovery skill when it is installed.

            Discovery finds what the project needs before agents can work on it.
            Call the loupe MCP tools readiness_get and workflow_get to learn the current setup.
            Read the repository: its build, its tests, its CI and its agent instructions.
            Submit your findings with readiness_report_submit.

            Discovery is read-only. Do not commit, push or create a branch.
            Do not create, edit or move a card.
            Do not change the project settings or its columns.

            PROMPT,
        'discovery-revise' => <<<'PROMPT'
            Run discovery {subjectId} of project {project} (projectId {projectId}).
            Use the loupe-discovery skill when it is installed.

            The owner asked for changes to the report. Read the comments on the report document, and address each one.
            Call the loupe MCP tool readiness_get for the runId and the report document.
            Check the repository again where a comment asks for it.
            Submit the revised report with readiness_report_submit and the same runId. Loupe stores it as a new version of the same document.

            Discovery is read-only. Do not commit, push or create a branch.
            Do not create, edit or move a card.
            Do not change the project settings or its columns.

            PROMPT,
    ];

    private const string EMPTY_CONTEXT = '{"pullRequestNumber":null,"pullRequestUrl":null,"headSha":null,"reason":null,"documentId":null,"inboxActionId":null,"inboxItemNumber":null,"inboxItemId":null,"epicBranch":null}';

    #[\Override]
    public function getDescription(): string
    {
        return 'Move the work of each live discovery run from its card to the run, and close the discovery cards of the ended runs';
    }

    public function up(Schema $schema): void
    {
        $this->moveLiveWork();
        $this->closeCardsOfEndedRuns();
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The cards keep their new column, and the previous image opens a new card request for a live run.
    }

    private function moveLiveWork(): void
    {
        // The latest live run of a card takes its requests. "wanted" marks the request that matches the state of that run.
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE discovery_moved_requests AS
            SELECT w.id AS request_id, w.project_id, w.subject_id AS card_id, run.id AS run_id,
                   w.rule_id = CASE run.state WHEN 'requested' THEN 'discovery' ELSE 'discovery-revise' END AS wanted
            FROM (
                SELECT DISTINCT ON (card_id) id, card_id, project_id, state FROM discovery_runs
                WHERE state IN ('requested', 'revising') AND card_id IS NOT NULL
                ORDER BY card_id, created_at DESC, id DESC
            ) run
            JOIN work_requests w ON w.subject_type = 'card' AND w.subject_id = run.card_id AND w.project_id = run.project_id
            WHERE w.rule_id IN ('discovery', 'discovery-revise') AND w.state IN ('open', 'claimed')
            SQL);

        // The rows a request about a run starts with. The open timeout counts from reopened_at, and a card request waited with no limit.
        foreach (self::PROMPTS as $ruleId => $prompt) {
            $this->addSql(<<<'SQL'
                UPDATE work_requests w
                SET subject_type = 'discovery', subject_id = m.run_id, card_number = NULL, capability = 'subject-discovery',
                    prompt = :prompt, context = :context, fail_when_no_taker = TRUE, model = NULL, effort = NULL, role = NULL,
                    round_number = NULL, resume_session_id = NULL, target_bridge_id = NULL,
                    reopened_at = CASE WHEN w.state = 'open' THEN CAST(NOW() AT TIME ZONE 'UTC' AS TIMESTAMP(0)) ELSE w.reopened_at END
                FROM discovery_moved_requests m
                WHERE w.id = m.request_id AND w.rule_id = :rule
                SQL, ['prompt' => $prompt, 'context' => self::EMPTY_CONTEXT, 'rule' => $ruleId]);
        }

        $this->addSql(<<<'SQL'
            UPDATE bridge_worker_runs r SET subject_type = 'discovery', subject_id = m.run_id, card_number = NULL
            FROM discovery_moved_requests m
            WHERE r.work_request_id = m.request_id AND r.project_id = m.project_id AND r.subject_type = 'card' AND r.subject_id = m.card_id
            SQL);
        // The tables that copy the subject of a worker run.
        foreach (['bridge_worker_run_usage' => '', 'bridge_worker_run_billed_costs' => '', 'bridge_worker_run_facts' => ', card_number = NULL'] as $table => $more) {
            $this->addSql(\sprintf(
                <<<'SQL'
                    UPDATE %s copy SET subject_type = 'discovery', subject_id = m.run_id%s
                    FROM bridge_worker_runs r
                    JOIN discovery_moved_requests m ON m.request_id = r.work_request_id AND m.run_id = r.subject_id
                    WHERE copy.run_id = r.id AND r.subject_type = 'discovery' AND copy.subject_type = 'card'
                    SQL,
                $table,
                $more,
            ));
        }

        $this->addSql('UPDATE discovery_runs d SET work_request_id = m.request_id FROM discovery_moved_requests m WHERE d.id = m.run_id AND m.wanted');
        $this->addSql('DROP TABLE discovery_moved_requests');
    }

    /** A card with a live run stays, because a worker of that run can still work in the folder of the card. */
    private function closeCardsOfEndedRuns(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE discovery_closed_cards AS
            SELECT c.id AS card_id, c.column_id AS source_id, target.id AS target_id
            FROM board_cards c
            JOIN board_columns source ON source.id = c.column_id AND NOT source.terminal
            JOIN LATERAL (
                SELECT id FROM board_columns WHERE project_id = c.project_id AND terminal ORDER BY position, id LIMIT 1
            ) target ON TRUE
            WHERE EXISTS (SELECT 1 FROM discovery_runs d WHERE d.card_id = c.id)
              AND NOT EXISTS (SELECT 1 FROM discovery_runs d WHERE d.card_id = c.id AND d.state IN ('requested', 'revising'))
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE board_cards c
            SET column_id = m.target_id, position = 0, completed_at = NOW() AT TIME ZONE 'UTC', completions = c.completions + 1,
                updated_at = NOW() AT TIME ZONE 'UTC', arrived_in_session = FALSE, left_backlog = TRUE
            FROM discovery_closed_cards m
            WHERE c.id = m.card_id
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE board_cards c SET position = ranked.rank
            FROM (
                SELECT id, row_number() OVER (PARTITION BY column_id ORDER BY position, created_at, id) - 1 AS rank
                FROM board_cards
                WHERE column_id IN (SELECT source_id FROM discovery_closed_cards)
            ) ranked
            WHERE c.id = ranked.id AND c.position <> ranked.rank
            SQL);
        $this->addSql('DROP TABLE discovery_closed_cards');
    }
}
