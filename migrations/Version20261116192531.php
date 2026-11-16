<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116192531 extends AbstractMigration
{
    private const string INSERTED = "now() AT TIME ZONE 'UTC'";

    /** The columns that only the engine, a heartbeat or a bookkeeping job writes, so no fact reads them. */
    private const array QUIET_COLUMNS = [
        'board_cards' => ['workflow_markers', 'inbox_markers', 'workflow_markers_due_at', 'updated_at', 'position', 'body', 'priority'],
        'bridges' => ['last_seen_at', 'update_state', 'update_version', 'hooks', 'skills', 'install_method', 'worker_pools', 'worker_pools_reported_at', 'accounts_reported_at', 'command_entries', 'command_entries_reported_at', 'worktrees', 'worktrees_reported_at', 'repositories_reported_at', 'git_identities', 'git_identities_reported_at', 'pause_requested_at', 'pause_requested_by_id', 'requested_name'],
        'bridge_account_readings' => ['seen_at'],
        'bridge_worker_runs' => ['search_vector', 'harness_events', 'tool_time_ms', 'idle_gap_ms', 'peak_context_tokens', 'wake_trigger', 'wake_gap_ms', 'wake_first_input_tokens', 'wake_first_cache_read_tokens', 'wake_first_cache_write_tokens', 'last_activity_at', 'usage_source', 'worker_pool', 'experiment', 'variant', 'requested_model', 'switched_from', 'harness', 'harness_session_id', 'harness_version', 'cli_version', 'skill_version', 'account', 'model', 'closed_api_key_id', 'closed_api_key_name'],
        'work_requests' => ['lease_until', 'claims', 'claim_token'],
        'forge_pull_requests' => ['refresh_attempts', 'next_refresh_at'],
        'forge_repositories' => ['last_accepted_at'],
        'forge_repository_checks' => ['next_read_ticket', 'accepted_read_ticket'],
        'workflow_setting_overrides' => ['read_at'],
        'documents' => ['search_text', 'search_language'],
        'inbox_items' => ['search_vector', 'search_language', 'updated_at'],
    ];

    /** The columns that this migration adds, which the catalog does not hold yet when it builds the triggers. */
    private const array ADDED_COLUMNS = [
        'bridges' => ['came_back_at'],
    ];

    /**
     * Each entry gives one trigger pair. The first trigger fires on the inserts and the deletes that the entry names,
     * and the second on the updates of the watched columns that change a value. 'only' narrows the watched columns,
     * and 'events' narrows the kinds of write.
     *
     * @var list<array{table: string, function: string, args?: list<string>, only?: list<string>, suffix?: string, events?: list<string>}>
     */
    private const array TRIGGERS = [
        // The row names the card.
        ['table' => 'board_card_events', 'function' => 'workflow_mark_card', 'args' => ['parent']],
        ['table' => 'board_card_values', 'function' => 'workflow_mark_card'],
        ['table' => 'board_card_artifacts', 'function' => 'workflow_mark_card', 'args' => ['children']],
        ['table' => 'board_card_documents', 'function' => 'workflow_mark_card', 'args' => ['children']],
        ['table' => 'board_card_verdicts', 'function' => 'workflow_mark_card_verdict'],
        ['table' => 'board_card_verdict_deliveries', 'function' => 'workflow_mark_via_verdict'],
        ['table' => 'card_pauses', 'function' => 'workflow_mark_card'],
        ['table' => 'bridge_card_holds', 'function' => 'workflow_mark_card'],
        ['table' => 'document_agent_reviews', 'function' => 'workflow_mark_card'],
        ['table' => 'discovery_runs', 'function' => 'workflow_mark_card'],
        ['table' => 'inbox_item_cards', 'function' => 'workflow_mark_card'],
        ['table' => 'workflow_rule_states', 'function' => 'workflow_mark_card', 'args' => ['', 'skip_checking']],
        ['table' => 'workflow_pending_baselines', 'function' => 'workflow_mark_card', 'args' => ['', 'skip_checking']],
        // The row names the card, and the cards around it.
        ['table' => 'board_cards', 'function' => 'workflow_mark_board_card'],
        ['table' => 'board_cards', 'function' => 'workflow_mark_card_parent', 'only' => ['workflow_markers'], 'suffix' => 'markers', 'events' => ['update']],
        ['table' => 'board_card_links', 'function' => 'workflow_mark_card_link'],
        ['table' => 'board_card_pull_requests', 'function' => 'workflow_mark_card_pull_request'],
        // The row names the card as a subject.
        ['table' => 'work_requests', 'function' => 'workflow_mark_subject', 'args' => ['children']],
        ['table' => 'bridge_worker_runs', 'function' => 'workflow_mark_worker_run'],
        ['table' => 'bridge_worker_run_states', 'function' => 'workflow_mark_via_run'],
        ['table' => 'bridge_work_matches', 'function' => 'workflow_mark_via_request'],
        ['table' => 'bridge_claim_refusals', 'function' => 'workflow_mark_via_request'],
        // The row belongs to a pull request that cards link.
        ['table' => 'forge_pull_requests', 'function' => 'workflow_mark_pull_request'],
        ['table' => 'agent_reviews', 'function' => 'workflow_mark_via_pull_request', 'args' => ['pull_request_id', 'card_id']],
        ['table' => 'agent_review_posts', 'function' => 'workflow_mark_via_review'],
        ['table' => 'board_pull_request_notices', 'function' => 'workflow_mark_via_pull_request', 'args' => ['forge_pull_request_id']],
        ['table' => 'board_pull_request_comments', 'function' => 'workflow_mark_via_pull_request', 'args' => ['forge_pull_request_id', 'card_id']],
        ['table' => 'board_site_review_check_states', 'function' => 'workflow_mark_via_pull_request', 'args' => ['pull_request_id']],
        ['table' => 'site_review_comments', 'function' => 'workflow_mark_site_review_comment', 'only' => ['status'], 'events' => ['update', 'delete']],
        // The row belongs to a document that cards link.
        ['table' => 'documents', 'function' => 'workflow_mark_document', 'args' => ['id']],
        ['table' => 'document_versions', 'function' => 'workflow_mark_document', 'args' => ['document_id']],
        ['table' => 'document_tags', 'function' => 'workflow_mark_document', 'args' => ['document_id']],
        ['table' => 'document_agent_reviews', 'function' => 'workflow_mark_document', 'args' => ['document_id'], 'suffix' => 'document'],
        ['table' => 'inbox_reviews', 'function' => 'workflow_mark_document', 'args' => ['document_id']],
        ['table' => 'reviews', 'function' => 'workflow_mark_via_version'],
        ['table' => 'tags', 'function' => 'workflow_mark_via_tag'],
        ['table' => 'inbox_items', 'function' => 'workflow_mark_via_item'],
        ['table' => 'inbox_asks', 'function' => 'workflow_mark_ask'],
        ['table' => 'inbox_ask_items', 'function' => 'workflow_mark_via_ask'],
        // The row is project-wide.
        ['table' => 'projects', 'function' => 'workflow_mark_project', 'args' => ['id']],
        ['table' => 'board_columns', 'function' => 'workflow_mark_project', 'args' => ['project_id']],
        ['table' => 'board_automation_settings', 'function' => 'workflow_mark_project', 'args' => ['project_id']],
        ['table' => 'forge_repositories', 'function' => 'workflow_mark_project', 'args' => ['project_id']],
        ['table' => 'forge_repository_checks', 'function' => 'workflow_mark_project', 'args' => ['project_id']],
        ['table' => 'workflow_bindings', 'function' => 'workflow_mark_project', 'args' => ['project_id']],
        ['table' => 'workflow_setting_overrides', 'function' => 'workflow_mark_project', 'args' => ['project_id']],
        ['table' => 'workflow_repository_settings', 'function' => 'workflow_mark_project', 'args' => ['project_id']],
        ['table' => 'workflow_slot_links', 'function' => 'workflow_mark_project', 'args' => ['project_id']],
        ['table' => 'feature_flag', 'function' => 'workflow_mark_all_projects'],
        // The row belongs to the owner or to a bridge.
        ['table' => 'bridges', 'function' => 'workflow_mark_owner_projects'],
        ['table' => 'bridge_account_readings', 'function' => 'workflow_mark_owner_projects'],
        ['table' => 'bridge_account_try_now', 'function' => 'workflow_mark_owner_projects'],
        ['table' => 'oauth_refresh_token_login', 'function' => 'workflow_mark_login_projects'],
        ['table' => 'oauth2_refresh_token', 'function' => 'workflow_mark_via_refresh_token', 'only' => ['revoked'], 'events' => ['update']],
        ['table' => 'github_installations', 'function' => 'workflow_mark_installation_projects'],
    ];

    /** The trigger functions, then the helpers that they call, each with its argument types. */
    private const array TRIGGER_FUNCTIONS = [
        'workflow_mark_card', 'workflow_mark_card_parent', 'workflow_mark_board_card', 'workflow_mark_card_link',
        'workflow_mark_card_pull_request', 'workflow_mark_card_verdict', 'workflow_mark_via_verdict', 'workflow_mark_subject',
        'workflow_mark_worker_run', 'workflow_mark_via_run', 'workflow_mark_via_request', 'workflow_mark_pull_request',
        'workflow_mark_via_pull_request', 'workflow_mark_via_review', 'workflow_mark_site_review_comment',
        'workflow_mark_document', 'workflow_mark_via_version', 'workflow_mark_via_tag', 'workflow_mark_via_item',
        'workflow_mark_ask', 'workflow_mark_via_ask', 'workflow_mark_project', 'workflow_mark_all_projects',
        'workflow_mark_owner_projects', 'workflow_mark_login_projects', 'workflow_mark_via_refresh_token',
        'workflow_mark_installation_projects',
    ];

    private const array HELPER_FUNCTIONS = [
        'workflow_touch_ask_cards(UUID)',
        'workflow_touch_session_cards(UUID, UUID, UUID)',
        'workflow_touch_document_cards(UUID)',
        'workflow_touch_pull_request_cards_by_id(UUID)',
        'workflow_touch_pull_request_cards(forge_pull_requests)',
        'workflow_touch_card_scope(UUID, TEXT)',
        'workflow_touch_login_projects(UUID)',
        'workflow_touch_all_projects()',
        'workflow_touch_owner_projects(UUID)',
        'workflow_touch_project(UUID)',
        'workflow_touch_card(UUID)',
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Mark the cards whose workflow facts changed: two mark tables, bridges.came_back_at, and a trigger on each table the workflow reads';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('CREATE TABLE workflow_card_changes (card_id UUID NOT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (card_id))');
        $this->addSql('CREATE TABLE workflow_project_changes (project_id UUID NOT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (project_id))');
        $this->addSql('ALTER TABLE bridges ADD came_back_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        foreach ($this->helperFunctions() as $sql) {
            $this->addSql($sql);
        }
        foreach ($this->triggerFunctions() as $sql) {
            $this->addSql($sql);
        }
        foreach (self::TRIGGERS as $trigger) {
            foreach ($this->triggerStatements($trigger) as $sql) {
                $this->addSql($sql);
            }
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (self::TRIGGER_FUNCTIONS as $function) {
            $this->addSql(\sprintf('DROP FUNCTION %s() CASCADE', $function));
        }
        foreach (self::HELPER_FUNCTIONS as $function) {
            $this->addSql(\sprintf('DROP FUNCTION %s', $function));
        }
        $this->addSql('ALTER TABLE bridges DROP came_back_at');
        $this->addSql('DROP TABLE workflow_project_changes');
        $this->addSql('DROP TABLE workflow_card_changes');
    }

    /**
     * @param array{table: string, function: string, args?: list<string>, only?: list<string>, suffix?: string, events?: list<string>} $trigger
     *
     * @return list<string>
     */
    private function triggerStatements(array $trigger): array
    {
        $table = $trigger['table'];
        $name = 'workflow_mark_'.$table.('' === ($trigger['suffix'] ?? '') ? '' : '_'.$trigger['suffix']);
        $execute = \sprintf('EXECUTE FUNCTION %s(%s)', $trigger['function'], implode(', ', array_map(static fn (string $arg): string => "'".$arg."'", $trigger['args'] ?? [])));
        $events = $trigger['events'] ?? ['insert', 'update', 'delete'];
        $statements = [];

        $kinds = array_map(strtoupper(...), array_values(array_intersect(['insert', 'delete'], $events)));
        if ([] !== $kinds) {
            $statements[] = \sprintf('CREATE TRIGGER %s AFTER %s ON %s FOR EACH ROW %s', $name, implode(' OR ', $kinds), $table, $execute);
        }

        if (\in_array('update', $events, true)) {
            $columns = $this->watchedColumns($table, $trigger['only'] ?? null);
            $guard = array_map(
                static fn (array $column): string => 'json' === $column['type']
                    ? \sprintf('OLD.%1$s::jsonb IS DISTINCT FROM NEW.%1$s::jsonb', $column['name'])
                    : \sprintf('OLD.%1$s IS DISTINCT FROM NEW.%1$s', $column['name']),
                $columns,
            );
            $statements[] = \sprintf(
                'CREATE TRIGGER %s_update AFTER UPDATE OF %s ON %s FOR EACH ROW WHEN (%s) %s',
                $name,
                implode(', ', array_column($columns, 'name')),
                $table,
                implode(' OR ', $guard),
                $execute,
            );
        }

        return $statements;
    }

    /**
     * @param list<string>|null $only
     *
     * @return non-empty-list<array{name: string, type: string}>
     */
    private function watchedColumns(string $table, ?array $only): array
    {
        /** @var list<array{name: string, type: string}> $columns */
        $columns = $this->connection->fetchAllAssociative(
            'SELECT column_name AS name, data_type AS type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table ORDER BY ordinal_position',
            ['table' => $table],
        );
        foreach (self::ADDED_COLUMNS[$table] ?? [] as $added) {
            $columns[] = ['name' => $added, 'type' => 'timestamp without time zone'];
        }
        $quiet = self::QUIET_COLUMNS[$table] ?? [];
        $columns = array_values(array_filter(
            $columns,
            static fn (array $column): bool => null === $only ? !\in_array($column['name'], $quiet, true) : \in_array($column['name'], $only, true),
        ));
        if ([] === $columns) {
            throw new \LogicException(\sprintf('The table %s has no column to watch.', $table));
        }

        return $columns;
    }

    /** @return list<string> */
    private function helperFunctions(): array
    {
        $at = self::INSERTED;

        return [
            <<<SQL
                CREATE FUNCTION workflow_touch_card(target UUID) RETURNS void AS \$\$
                BEGIN
                    IF target IS NOT NULL THEN
                        INSERT INTO workflow_card_changes (card_id, changed_at) VALUES (target, {$at})
                        ON CONFLICT (card_id) DO UPDATE SET changed_at = EXCLUDED.changed_at;
                    END IF;
                END;
                \$\$ LANGUAGE plpgsql
                SQL,
            <<<SQL
                CREATE FUNCTION workflow_touch_project(target UUID) RETURNS void AS \$\$
                BEGIN
                    IF target IS NOT NULL THEN
                        INSERT INTO workflow_project_changes (project_id, changed_at) VALUES (target, {$at})
                        ON CONFLICT (project_id) DO UPDATE SET changed_at = EXCLUDED.changed_at;
                    END IF;
                END;
                \$\$ LANGUAGE plpgsql
                SQL,
            <<<SQL
                CREATE FUNCTION workflow_touch_owner_projects(owner UUID) RETURNS void AS \$\$
                BEGIN
                    INSERT INTO workflow_project_changes (project_id, changed_at)
                    SELECT p.id, {$at} FROM projects p WHERE p.owner_id = owner
                    ON CONFLICT (project_id) DO UPDATE SET changed_at = EXCLUDED.changed_at;
                END;
                \$\$ LANGUAGE plpgsql
                SQL,
            <<<SQL
                CREATE FUNCTION workflow_touch_all_projects() RETURNS void AS \$\$
                BEGIN
                    INSERT INTO workflow_project_changes (project_id, changed_at)
                    SELECT b.project_id, {$at} FROM workflow_bindings b
                    ON CONFLICT (project_id) DO UPDATE SET changed_at = EXCLUDED.changed_at;
                END;
                \$\$ LANGUAGE plpgsql
                SQL,
            <<<SQL
                CREATE FUNCTION workflow_touch_login_projects(login UUID) RETURNS void AS \$\$
                BEGIN
                    INSERT INTO workflow_project_changes (project_id, changed_at)
                    SELECT DISTINCT p.id, {$at} FROM bridges b JOIN projects p ON p.owner_id = b.owner_id WHERE b.login_id = login
                    ON CONFLICT (project_id) DO UPDATE SET changed_at = EXCLUDED.changed_at;
                END;
                \$\$ LANGUAGE plpgsql
                SQL,
            // The scope lists what else reads the card: its parent, its children, and the cards that its links hold up.
            <<<'SQL'
                CREATE FUNCTION workflow_touch_card_scope(target UUID, scope TEXT) RETURNS void AS $$
                BEGIN
                    IF target IS NULL THEN
                        RETURN;
                    END IF;
                    PERFORM workflow_touch_card(target);
                    IF position('parent' IN scope) > 0 THEN
                        PERFORM workflow_touch_card(c.parent_card_id) FROM board_cards c WHERE c.id = target;
                    END IF;
                    IF position('children' IN scope) > 0 THEN
                        PERFORM workflow_touch_card(c.id) FROM board_cards c WHERE c.parent_card_id = target;
                    END IF;
                    IF position('dependents' IN scope) > 0 THEN
                        PERFORM workflow_touch_card(l.target_card_id) FROM board_card_links l
                        WHERE l.source_card_id = target AND l.kind IN ('blocks', 'has-follow-up');
                    END IF;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // The cards that link the pull request, those that link a pull request stacked on it, and their parents and children.
            <<<'SQL'
                CREATE FUNCTION workflow_touch_pull_request_cards(pr forge_pull_requests) RETURNS void AS $$
                BEGIN
                    PERFORM workflow_touch_card_scope(c.id, 'parent,children')
                    FROM board_card_pull_requests l
                    JOIN board_cards c ON c.id = l.card_id
                    WHERE c.project_id = pr.project_id AND l.forge = pr.forge AND lower(l.repository) = lower(pr.repository) AND l.number = pr.number;
                    IF pr.head_branch IS NOT NULL THEN
                        PERFORM workflow_touch_card_scope(c.id, 'parent,children')
                        FROM forge_pull_requests s
                        JOIN board_card_pull_requests l ON l.forge = s.forge AND lower(l.repository) = lower(s.repository) AND l.number = s.number
                        JOIN board_cards c ON c.id = l.card_id AND c.project_id = s.project_id
                        WHERE s.project_id = pr.project_id AND s.forge = pr.forge AND lower(s.repository) = lower(pr.repository) AND s.base_branch = pr.head_branch;
                    END IF;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_touch_pull_request_cards_by_id(target UUID) RETURNS void AS $$
                BEGIN
                    IF target IS NOT NULL THEN
                        PERFORM workflow_touch_pull_request_cards(p) FROM forge_pull_requests p WHERE p.id = target;
                    END IF;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // The cards that link the document, and their children, which read the documents of their parent.
            <<<'SQL'
                CREATE FUNCTION workflow_touch_document_cards(target UUID) RETURNS void AS $$
                BEGIN
                    IF target IS NOT NULL THEN
                        PERFORM workflow_touch_card_scope(d.card_id, 'children') FROM board_card_documents d WHERE d.document_id = target;
                    END IF;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // The cards whose newest run sits in the same session read the runs of that session.
            <<<'SQL'
                CREATE FUNCTION workflow_touch_session_cards(target_project UUID, target_bridge UUID, target_session UUID) RETURNS void AS $$
                BEGIN
                    IF target_session IS NOT NULL THEN
                        PERFORM workflow_touch_card(r.subject_id) FROM bridge_worker_runs r
                        WHERE r.project_id = target_project AND r.bridge_id = target_bridge AND r.session_id = target_session AND r.subject_type = 'card';
                    END IF;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // The card of the ask, the cards whose runs share its session, and the cards whose newest run waits on it.
            <<<'SQL'
                CREATE FUNCTION workflow_touch_ask_cards(target UUID) RETURNS void AS $$
                BEGIN
                    IF target IS NULL THEN
                        RETURN;
                    END IF;
                    PERFORM workflow_touch_card(a.card_id) FROM inbox_asks a WHERE a.id = target;
                    PERFORM workflow_touch_card(r.subject_id)
                    FROM inbox_asks a
                    JOIN bridge_worker_runs r ON r.subject_type = 'card'
                        AND ((r.project_id = a.project_id AND r.bridge_id = a.bridge_id AND r.session_id = a.session_id) OR r.waits_on_ask_id = a.id)
                    WHERE a.id = target;
                END;
                $$ LANGUAGE plpgsql
                SQL,
        ];
    }

    /** @return list<string> */
    private function triggerFunctions(): array
    {
        return [
            // The row has a card_id. The first argument is the scope, and the second tells the check to skip its own card.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_card() RETURNS trigger AS $$
                DECLARE
                    scope TEXT := COALESCE(TG_ARGV[0], '');
                    checking TEXT := CASE WHEN TG_ARGV[1] = 'skip_checking' THEN current_setting('app.workflow_checking_card', true) END;
                BEGIN
                    IF TG_OP <> 'DELETE' AND NEW.card_id::text IS DISTINCT FROM checking THEN
                        PERFORM workflow_touch_card_scope(NEW.card_id, scope);
                    END IF;
                    IF TG_OP <> 'INSERT' AND OLD.card_id::text IS DISTINCT FROM checking
                        AND (TG_OP = 'DELETE' OR OLD.card_id IS DISTINCT FROM NEW.card_id) THEN
                        PERFORM workflow_touch_card_scope(OLD.card_id, scope);
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_card_parent() RETURNS trigger AS $$
                BEGIN
                    PERFORM workflow_touch_card(NEW.parent_card_id);
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // A card is read by itself, by its parent and its children, and by the cards that it blocks or that follow it.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_board_card() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_card_scope(NEW.id, 'children,dependents');
                        PERFORM workflow_touch_card(NEW.parent_card_id);
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_card(OLD.parent_card_id);
                        IF TG_OP = 'DELETE' THEN
                            PERFORM workflow_touch_card_scope(OLD.id, 'children,dependents');
                        END IF;
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_card_link() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_card(NEW.source_card_id);
                        PERFORM workflow_touch_card(NEW.target_card_id);
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_card(OLD.source_card_id);
                        PERFORM workflow_touch_card(OLD.target_card_id);
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // A link to a pull request marks the card, its parent and children, and every card on that pull request or stacked on it.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_card_pull_request() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_card_scope(NEW.card_id, 'parent,children');
                        PERFORM workflow_touch_pull_request_cards(p)
                        FROM board_cards c
                        JOIN forge_pull_requests p ON p.project_id = c.project_id AND p.forge = NEW.forge AND lower(p.repository) = lower(NEW.repository) AND p.number = NEW.number
                        WHERE c.id = NEW.card_id;
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_card_scope(OLD.card_id, 'parent,children');
                        PERFORM workflow_touch_pull_request_cards(p)
                        FROM board_cards c
                        JOIN forge_pull_requests p ON p.project_id = c.project_id AND p.forge = OLD.forge AND lower(p.repository) = lower(OLD.repository) AND p.number = OLD.number
                        WHERE c.id = OLD.card_id;
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // The notes of a verdict count for every card that links a pull request of the card.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_card_verdict() RETURNS trigger AS $$
                DECLARE
                    target UUID;
                BEGIN
                    FOREACH target IN ARRAY ARRAY[CASE WHEN TG_OP <> 'DELETE' THEN NEW.card_id END, CASE WHEN TG_OP <> 'INSERT' THEN OLD.card_id END] LOOP
                        PERFORM workflow_touch_card_scope(target, 'parent');
                        PERFORM workflow_touch_pull_request_cards(p)
                        FROM board_card_pull_requests l
                        JOIN board_cards c ON c.id = l.card_id
                        JOIN forge_pull_requests p ON p.project_id = c.project_id AND p.forge = l.forge AND lower(p.repository) = lower(l.repository) AND p.number = l.number
                        WHERE l.card_id = target;
                    END LOOP;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_via_verdict() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_card(v.card_id) FROM board_card_verdicts v WHERE v.id = NEW.verdict_id;
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_card(v.card_id) FROM board_card_verdicts v WHERE v.id = OLD.verdict_id;
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // The row names its card as a subject. The argument is the scope.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_subject() RETURNS trigger AS $$
                DECLARE
                    scope TEXT := COALESCE(TG_ARGV[0], '');
                BEGIN
                    IF TG_OP <> 'DELETE' AND NEW.subject_type = 'card' THEN
                        PERFORM workflow_touch_card_scope(NEW.subject_id, scope);
                    END IF;
                    IF TG_OP <> 'INSERT' AND OLD.subject_type = 'card' THEN
                        PERFORM workflow_touch_card_scope(OLD.subject_id, scope);
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // A run is read by its card, by the parent and the children of that card, and by the cards of its session.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_worker_run() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        IF NEW.subject_type = 'card' THEN
                            PERFORM workflow_touch_card_scope(NEW.subject_id, 'parent,children');
                        END IF;
                        PERFORM workflow_touch_session_cards(NEW.project_id, NEW.bridge_id, NEW.session_id);
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        IF OLD.subject_type = 'card' THEN
                            PERFORM workflow_touch_card_scope(OLD.subject_id, 'parent,children');
                        END IF;
                        PERFORM workflow_touch_session_cards(OLD.project_id, OLD.bridge_id, OLD.session_id);
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_via_run() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_card(r.subject_id) FROM bridge_worker_runs r WHERE r.id = NEW.run_id AND r.subject_type = 'card';
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_card(r.subject_id) FROM bridge_worker_runs r WHERE r.id = OLD.run_id AND r.subject_type = 'card';
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_via_request() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_card(w.subject_id) FROM work_requests w WHERE w.id = NEW.work_request_id AND w.subject_type = 'card';
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_card(w.subject_id) FROM work_requests w WHERE w.id = OLD.work_request_id AND w.subject_type = 'card';
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_pull_request() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_pull_request_cards(NEW);
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_pull_request_cards(OLD);
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // The first argument names the column that holds the pull request id, and the optional second one the column that holds a card id.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_via_pull_request() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_pull_request_cards_by_id((to_jsonb(NEW) ->> TG_ARGV[0])::uuid);
                        IF TG_NARGS > 1 THEN
                            PERFORM workflow_touch_card_scope((to_jsonb(NEW) ->> TG_ARGV[1])::uuid, 'parent');
                        END IF;
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_pull_request_cards_by_id((to_jsonb(OLD) ->> TG_ARGV[0])::uuid);
                        IF TG_NARGS > 1 THEN
                            PERFORM workflow_touch_card_scope((to_jsonb(OLD) ->> TG_ARGV[1])::uuid, 'parent');
                        END IF;
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_via_review() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_pull_request_cards_by_id(r.pull_request_id), workflow_touch_card_scope(r.card_id, 'parent')
                        FROM agent_reviews r WHERE r.id = NEW.review_id;
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_pull_request_cards_by_id(r.pull_request_id), workflow_touch_card_scope(r.card_id, 'parent')
                        FROM agent_reviews r WHERE r.id = OLD.review_id;
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // A comment counts through the notes of the verdicts that carry it, for every card on the pull requests of those cards.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_site_review_comment() RETURNS trigger AS $$
                DECLARE
                    comment_id UUID := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
                BEGIN
                    PERFORM workflow_touch_card_scope(v.card_id, 'parent')
                    FROM board_card_verdicts v
                    WHERE v.notes @> jsonb_build_array(jsonb_build_object('id', comment_id::text));
                    PERFORM workflow_touch_pull_request_cards(p)
                    FROM board_card_verdicts v
                    JOIN board_card_pull_requests l ON l.card_id = v.card_id
                    JOIN board_cards c ON c.id = v.card_id
                    JOIN forge_pull_requests p ON p.project_id = c.project_id AND p.forge = l.forge AND lower(p.repository) = lower(l.repository) AND p.number = l.number
                    WHERE v.notes @> jsonb_build_array(jsonb_build_object('id', comment_id::text));
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // The argument names the column that holds the document id.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_document() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_document_cards((to_jsonb(NEW) ->> TG_ARGV[0])::uuid);
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_document_cards((to_jsonb(OLD) ->> TG_ARGV[0])::uuid);
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_via_version() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_document_cards(v.document_id) FROM document_versions v WHERE v.id = NEW.version_id;
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_document_cards(v.document_id) FROM document_versions v WHERE v.id = OLD.version_id;
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_via_tag() RETURNS trigger AS $$
                DECLARE
                    target UUID := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
                BEGIN
                    PERFORM workflow_touch_document_cards(t.document_id) FROM document_tags t WHERE t.tag_id = target;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // An item reaches its cards directly, through the document of its review, and through the asks that hold it.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_via_item() RETURNS trigger AS $$
                DECLARE
                    target UUID := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;
                BEGIN
                    PERFORM workflow_touch_card(ic.card_id) FROM inbox_item_cards ic WHERE ic.item_id = target;
                    PERFORM workflow_touch_document_cards(r.document_id) FROM inbox_reviews r WHERE r.item_id = target;
                    PERFORM workflow_touch_ask_cards(l.ask_id) FROM inbox_ask_items l WHERE l.item_id = target;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_ask() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_ask_cards(NEW.id);
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_card(OLD.card_id);
                        PERFORM workflow_touch_session_cards(OLD.project_id, OLD.bridge_id, OLD.session_id);
                        PERFORM workflow_touch_card(r.subject_id) FROM bridge_worker_runs r WHERE r.waits_on_ask_id = OLD.id AND r.subject_type = 'card';
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_via_ask() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_ask_cards(NEW.ask_id);
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_ask_cards(OLD.ask_id);
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // The argument names the column that holds the project id. A deleted project keeps no mark.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_project() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_project((to_jsonb(NEW) ->> TG_ARGV[0])::uuid);
                    END IF;
                    IF TG_OP <> 'INSERT' AND NOT (TG_OP = 'DELETE' AND TG_TABLE_NAME = 'projects') THEN
                        PERFORM workflow_touch_project((to_jsonb(OLD) ->> TG_ARGV[0])::uuid);
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_all_projects() RETURNS trigger AS $$
                BEGIN
                    PERFORM workflow_touch_all_projects();
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_owner_projects() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_owner_projects(NEW.owner_id);
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_owner_projects(OLD.owner_id);
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_login_projects() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'DELETE' THEN
                        PERFORM workflow_touch_login_projects(NEW.login_id);
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        PERFORM workflow_touch_login_projects(OLD.login_id);
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE FUNCTION workflow_mark_via_refresh_token() RETURNS trigger AS $$
                BEGIN
                    PERFORM workflow_touch_login_projects(l.login_id) FROM oauth_refresh_token_login l WHERE l.refresh_token_identifier = NEW.identifier;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            // An installation serves the repositories that name its id as their source.
            <<<'SQL'
                CREATE FUNCTION workflow_mark_installation_projects() RETURNS trigger AS $$
                DECLARE
                    target BIGINT := CASE WHEN TG_OP = 'DELETE' THEN OLD.installation_id ELSE NEW.installation_id END;
                BEGIN
                    INSERT INTO workflow_project_changes (project_id, changed_at)
                    SELECT DISTINCT r.project_id, now() AT TIME ZONE 'UTC' FROM forge_repositories r
                    WHERE r.source = 'installation' AND r.source_ref = target::text
                    ON CONFLICT (project_id) DO UPDATE SET changed_at = EXCLUDED.changed_at;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
        ];
    }
}
