<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Mark;

/**
 * One case for each table that a workflow fact reads. 'row' is what the insert writes, 'set' is what the update
 * changes, and 'cards' and 'projects' name what each of the three writes marks. An 'expect' entry replaces that
 * answer for one write. An extra is one more write with its own answer.
 *
 * @phpstan-type Marked array{list<string>, list<string>}
 * @phpstan-type Case array{row: array<string, mixed>, set: array<string, mixed>, cards: list<string>, projects: list<string>, expect?: array<string, Marked>, extras?: array<string, array{\Closure(RawRows): void, list<string>, list<string>}>, skipsCheckedCard?: bool}
 */
final class MarkCases
{
    /** @return array<string, Case> */
    public static function all(): array
    {
        $id = MarkWorld::id(...);
        $nothing = [[], []];
        $parentAndChildren = ['P', 'C1', 'C2'];
        $pullRequestCards = ['C1', 'P', 'S'];
        $askCards = ['N1', 'C2', 'W'];
        $someTime = '2027-01-01 00:00:00';

        return [
            'board_card_events' => self::row(['card_id' => $id('C1'), 'project_id' => $id('PX')], ['actor_kind' => 'agent'], ['C1', 'P']),
            'board_card_values' => self::row(['card_id' => $id('C1'), 'key' => 'k'], ['value' => ['v' => 1]], ['C1']),
            'board_card_artifacts' => self::row(['card_id' => $id('P')], ['linked_at' => $someTime], $parentAndChildren),
            'board_card_documents' => self::row(['card_id' => $id('P'), 'document_id' => $id('X1')], ['linked_at' => $someTime], $parentAndChildren),
            'board_card_verdicts' => self::row(['card_id' => $id('C1')], ['message' => 'changed'], $pullRequestCards),
            'board_card_verdict_deliveries' => self::row(['verdict_id' => $id('V1')], ['state' => 'done'], ['C1']),
            'card_pauses' => self::row(['card_id' => $id('C1'), 'project_id' => $id('PX')], ['reason' => 'changed'], ['C1']),
            'bridge_card_holds' => self::row(['card_id' => $id('C1'), 'project_id' => $id('PX')], ['held_at' => $someTime], ['C1']),
            'bridge_worker_waits' => self::row(['card_id' => $id('C1'), 'project_id' => $id('PX'), 'run_id' => $id('X1'), 'chain_root_run_id' => $id('X1'), 'bridge_id' => $id('BR1'), 'session_id' => $id('X1'), 'conditions' => [], 'opened_at' => $someTime, 'limit_at' => $someTime], ['ended_at' => $someTime], ['C1']),
            'document_agent_reviews' => self::row(['card_id' => $id('C1'), 'document_id' => $id('D1'), 'project_id' => $id('PX')], ['summary' => 'changed'], $parentAndChildren),
            'discovery_runs' => self::row(['card_id' => $id('C1'), 'project_id' => $id('PX')], ['state' => 'failed'], ['C1']),
            'inbox_decisions' => self::row(['card_id' => $id('C1'), 'project_id' => $id('PX'), 'label' => 'naming', 'choice' => 'a', 'other_options' => [], 'made_by' => 'logged', 'created_at' => $someTime], ['changed_note' => 'changed'], ['C1']),
            'inbox_item_cards' => self::row(['card_id' => $id('C1')], ['linked_at' => $someTime], ['C1']),
            'workflow_rule_states' => self::row(['card_id' => $id('C1'), 'project_id' => $id('PX'), 'rule_id' => 'case'], ['truth' => true], ['C1'], rest: ['skipsCheckedCard' => true]),
            'workflow_pending_baselines' => self::row(['card_id' => $id('C1'), 'project_id' => $id('PX')], ['created_at' => $someTime], ['C1'], rest: ['skipsCheckedCard' => true]),
            'board_cards' => self::row(
                ['id' => $id('X1'), 'project_id' => $id('PX'), 'column_id' => $id('COLX'), 'number' => 99, 'parent_card_id' => $id('P')],
                ['title' => 'changed'],
                ['X1', 'P'],
                rest: ['extras' => [
                    'a blocker marks the card it blocks' => [static fn (RawRows $rows) => $rows->update('board_cards', ['id' => $id('B')], ['title' => 'changed']), ['B', 'C1'], []],
                    'a parent marks its children' => [static fn (RawRows $rows) => $rows->update('board_cards', ['id' => $id('P')], ['title' => 'changed']), [...$parentAndChildren, 'X1'], []],
                    'a child marks its parent' => [static fn (RawRows $rows) => $rows->update('board_cards', ['id' => $id('C1')], ['title' => 'changed']), ['C1', 'P'], []],
                    'the markers of a child mark its parent alone' => [static fn (RawRows $rows) => $rows->update('board_cards', ['id' => $id('C1')], ['workflow_markers' => ['k' => 1]]), ['P'], []],
                    'the due time of a card marks nothing' => [static fn (RawRows $rows) => $rows->update('board_cards', ['id' => $id('C1')], ['workflow_markers_due_at' => $someTime]), [], []],
                    'the inbox markers of a card mark nothing' => [static fn (RawRows $rows) => $rows->update('board_cards', ['id' => $id('C1')], ['inbox_markers' => ['k' => 1]]), [], []],
                ]],
            ),
            'board_card_links' => self::row(['source_card_id' => $id('C2'), 'target_card_id' => $id('C1'), 'kind' => 'blocks'], ['kind' => 'has-follow-up'], ['C1', 'C2']),
            'board_card_pull_requests' => self::row(['card_id' => $id('C2'), 'forge' => 'github', 'repository' => 'o/r', 'number' => 1, 'url' => 'https://github.test/o/r/pull/1'], ['url' => 'https://github.test/o/r/pull/1?changed'], ['C1', 'C2', 'P', 'S']),
            'work_requests' => self::row(
                ['project_id' => $id('PX'), 'subject_type' => 'card', 'subject_id' => $id('P')],
                ['state' => 'done'],
                $parentAndChildren,
                rest: ['extras' => [
                    'the lease of a request marks nothing' => [static fn (RawRows $rows) => $rows->update('work_requests', ['id' => $id('WR0')], ['lease_until' => $someTime]), [], []],
                    'the state of a request marks its card' => [static fn (RawRows $rows) => $rows->update('work_requests', ['id' => $id('WR0')], ['state' => 'done']), ['C2'], []],
                ]],
            ),
            'bridge_worker_runs' => self::row(
                ['project_id' => $id('PX'), 'subject_type' => 'card', 'subject_id' => $id('C1'), 'bridge_id' => $id('BR1'), 'session_id' => $id('SS1')],
                ['output' => 'changed'],
                ['C1', 'P', 'C2'],
            ),
            'bridge_worker_run_states' => self::row(['run_id' => $id('R0')], ['state' => 'done'], ['C2']),
            'bridge_work_matches' => self::row(['work_request_id' => $id('WR0')], ['account' => 'changed'], ['C2']),
            'bridge_claim_refusals' => self::row(['work_request_id' => $id('WR0')], ['account' => 'changed'], ['C2']),
            'forge_pull_requests' => self::row(
                ['project_id' => $id('PX'), 'forge' => 'github', 'repository' => 'o/r', 'number' => 3, 'head_branch' => 'f3', 'base_branch' => 'main'],
                ['checks' => 'passed'],
                ['C2', 'P'],
                rest: ['extras' => [
                    'a pull request marks its card, and the card stacked on it' => [static fn (RawRows $rows) => $rows->update('forge_pull_requests', ['id' => $id('PR1')], ['checks' => 'passed']), $pullRequestCards, []],
                    'a stacked pull request marks its own card alone' => [static fn (RawRows $rows) => $rows->update('forge_pull_requests', ['id' => $id('PR2')], ['checks' => 'passed']), ['S'], []],
                    'a new head branch marks the cards of both branches' => [static fn (RawRows $rows) => $rows->update('forge_pull_requests', ['id' => $id('PR1')], ['head_branch' => 'renamed']), $pullRequestCards, []],
                    'the refresh time of a pull request marks nothing' => [static fn (RawRows $rows) => $rows->update('forge_pull_requests', ['id' => $id('PR1')], ['next_refresh_at' => $someTime]), [], []],
                ]],
            ),
            'agent_reviews' => self::row(['project_id' => $id('PX'), 'card_id' => $id('C2'), 'pull_request_id' => $id('PR1')], ['summary' => 'changed'], ['C1', 'C2', 'P', 'S']),
            'agent_review_posts' => self::row(['review_id' => $id('AR0')], ['head_sha' => 'changed'], ['C1', 'C2', 'P', 'S']),
            'board_pull_request_notices' => self::row(['forge_pull_request_id' => $id('PR1'), 'project_id' => $id('PX')], ['attempts' => 2], $pullRequestCards),
            'board_pull_request_comments' => self::row(['forge_pull_request_id' => $id('PR1'), 'card_id' => $id('C2'), 'project_id' => $id('PX')], ['attempts' => 2], ['C1', 'C2', 'P', 'S']),
            'board_site_review_check_states' => self::row(['pull_request_id' => $id('PR1')], ['conclusion' => 'changed'], $pullRequestCards),
            'site_review_comments' => self::row(
                ['id' => $id('K1'), 'project_id' => $id('PX'), 'url' => 'https://site.test/'],
                ['status' => 'resolved'],
                $pullRequestCards,
                rest: ['expect' => ['insert' => $nothing]],
            ),
            'documents' => self::row(['id' => $id('D1'), 'owner_id' => $id('OX'), 'project_id' => $id('PX')], ['title' => 'changed'], $parentAndChildren),
            'document_versions' => self::row(['document_id' => $id('D1'), 'version_number' => 5], ['description' => 'changed'], $parentAndChildren),
            'document_tags' => self::row(['document_id' => $id('D1'), 'tag_id' => $id('X1')], ['tag_id' => $id('PN')], $parentAndChildren),
            'inbox_reviews' => self::row(['document_id' => $id('D1')], ['target_label' => 'changed'], $parentAndChildren),
            'reviews' => self::row(['version_id' => $id('DV1')], ['note' => 'changed'], $parentAndChildren),
            'tags' => self::row(['id' => $id('T1'), 'project_id' => $id('PX')], ['name' => 'changed'], $parentAndChildren),
            'inbox_items' => self::row(['id' => $id('I1'), 'project_id' => $id('PX'), 'number' => 1], ['title' => 'changed'], ['C1', 'P', 'C2', 'N1', 'W']),
            'inbox_asks' => self::row(
                ['id' => $id('A2'), 'card_id' => $id('N1'), 'project_id' => $id('PX'), 'bridge_id' => $id('BR1'), 'session_id' => $id('SS1'), 'closed_at' => '2026-01-01 00:00:00'],
                ['context' => 'changed'],
                ['N1', 'C2'],
                rest: ['extras' => [
                    'an ask marks the card that waits on it' => [static fn (RawRows $rows) => $rows->update('inbox_asks', ['id' => $id('A1')], ['context' => 'changed']), $askCards, []],
                    'a deleted ask marks the card that waited on it' => [static fn (RawRows $rows) => $rows->delete('inbox_asks', ['id' => $id('A1')]), $askCards, []],
                ]],
            ),
            'inbox_ask_items' => self::row(['ask_id' => $id('A1'), 'item_id' => $id('PN')], ['read_at' => $someTime], $askCards),
            'projects' => self::row(['id' => $id('PN'), 'owner_id' => $id('OX')], ['name' => 'changed'], projects: ['PN'], rest: ['expect' => ['delete' => $nothing]]),
            'board_columns' => self::row(['project_id' => $id('PX'), 'slug' => 'case'], ['label' => 'changed'], projects: ['PX']),
            'board_automation_settings' => self::row(['project_id' => $id('PX')], ['enabled' => true], projects: ['PX']),
            'forge_repositories' => self::row(['project_id' => $id('PX')], ['path' => 'changed'], projects: ['PX']),
            'forge_repository_checks' => self::row(['project_id' => $id('PX')], ['failure' => 'changed'], projects: ['PX']),
            'workflow_bindings' => self::row(['project_id' => $id('PN'), 'definition' => (object) []], ['template_key' => 'changed'], projects: ['PN']),
            'workflow_setting_overrides' => self::row(['project_id' => $id('PX')], ['value' => 'changed'], projects: ['PX']),
            'workflow_repository_settings' => self::row(['project_id' => $id('PX')], ['value' => 'changed'], projects: ['PX']),
            'workflow_slot_links' => self::row(['project_id' => $id('PX'), 'slot_key' => 'case'], ['slot_key' => 'changed'], projects: ['PX']),
            'feature_flag' => self::row(['name' => 'case', 'type' => 'boolean', 'tags' => []], ['name' => 'changed'], projects: ['PX', 'PY']),
            'bridges' => self::row(
                ['id' => $id('PN'), 'owner_id' => $id('OX')],
                ['came_back_at' => $someTime],
                projects: ['PX'],
                rest: ['extras' => [
                    'a heartbeat marks nothing' => [static fn (RawRows $rows) => $rows->update('bridges', ['owner_id' => $id('OX'), 'id' => $id('BR1')], ['last_seen_at' => $someTime]), [], []],
                    'a report of the worker pools marks nothing' => [static fn (RawRows $rows) => $rows->update('bridges', ['owner_id' => $id('OX'), 'id' => $id('BR1')], ['worker_pools' => [['name' => 'a']]]), [], []],
                ]],
            ),
            'bridge_account_readings' => self::row(['owner_id' => $id('OX'), 'bridge_id' => $id('BR1')], ['limited' => true], projects: ['PX']),
            'bridge_account_try_now' => self::row(['owner_id' => $id('OX'), 'bridge_id' => $id('BR1')], ['still_limited' => true], projects: ['PX']),
            'oauth_refresh_token_login' => self::row(
                ['login_id' => $id('LG1'), 'refresh_token_identifier' => MarkWorld::token('tok-case')],
                ['refresh_token_identifier' => MarkWorld::token('tok-case-changed')],
                projects: ['PX'],
            ),
            'oauth2_refresh_token' => self::row(
                ['identifier' => MarkWorld::token(MarkWorld::TOKEN)],
                ['revoked' => true],
                projects: ['PX'],
                rest: ['expect' => ['insert' => $nothing, 'delete' => $nothing]],
            ),
            'github_installations' => self::row(['installation_id' => MarkWorld::INSTALLATION], ['account_login' => 'changed'], projects: ['PX']),
        ];
    }

    /**
     * @param array<string, mixed>                                                                                                                               $values
     * @param array<string, mixed>                                                                                                                               $set
     * @param list<string>                                                                                                                                       $cards
     * @param list<string>                                                                                                                                       $projects
     * @param array{expect?: array<string, Marked>, extras?: array<string, array{\Closure(RawRows): void, list<string>, list<string>}>, skipsCheckedCard?: bool} $rest
     *
     * @return Case
     */
    private static function row(array $values, array $set, array $cards = [], array $projects = [], array $rest = []): array
    {
        return ['row' => $values, 'set' => $set, 'cards' => $cards, 'projects' => $projects, ...$rest];
    }
}
