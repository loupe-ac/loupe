<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Mark;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Project PX holds a parent P with two children C1 and C2, a blocker B of C1, a card S whose pull request is
 * stacked on the pull request of C1, a card N1 that asks, a card W that waits, and a card U that nothing touches.
 * Project PY has a card Z. Owner OX holds PX, and owner OY holds PY.
 *
 * A name that no row holds yet, such as D1 or K1, is the id of the row that a test inserts.
 */
final readonly class MarkWorld
{
    private const string NAMESPACE = '6f1d6a53-6a0b-4d5e-9d6c-2a1d3f5b7c90';

    public const string TOKEN = 'tok-world';
    public const int INSTALLATION = 4242;

    public function __construct(
        public RawRows $rows,
        public Connection $db,
    ) {
    }

    public static function id(string $name): string
    {
        return Uuid::v5(Uuid::fromString(self::NAMESPACE), $name)->toRfc4122();
    }

    public static function token(string $name): string
    {
        return str_pad($name, 80, 'x');
    }

    /** @return array<string, string> */
    public static function names(): array
    {
        $names = [];
        foreach (['OX', 'OY', 'PX', 'PY', 'PN', 'P', 'C1', 'C2', 'B', 'S', 'U', 'N1', 'W', 'Z', 'X1', 'D1', 'DV1', 'T1', 'I1', 'A1', 'A2', 'BR1', 'LG1', 'SS1', 'R0', 'R1', 'WR0', 'V1', 'K1', 'AR0', 'PR1', 'PR2', 'COLX', 'COLY'] as $name) {
            $names[self::id($name)] = $name;
        }

        return $names;
    }

    /** @param array<mixed> $template the definition that the workflow binding of project PX holds */
    public static function seed(RawRows $rows, Connection $db, array $template = []): self
    {
        $world = new self($rows, $db);
        $id = self::id(...);

        $rows->insert('users', ['id' => $id('OX')]);
        $rows->insert('users', ['id' => $id('OY')]);
        $rows->insert('projects', ['id' => $id('PX'), 'owner_id' => $id('OX')]);
        $rows->insert('projects', ['id' => $id('PY'), 'owner_id' => $id('OY')]);
        $rows->insert('board_columns', ['id' => $id('COLX'), 'project_id' => $id('PX'), 'slug' => 'todo', 'terminal' => false]);
        $rows->insert('board_columns', ['id' => $id('COLY'), 'project_id' => $id('PY'), 'slug' => 'todo', 'terminal' => false]);
        $rows->insert('workflow_bindings', ['project_id' => $id('PX'), 'template_key' => 'lifecycle', 'definition' => $template ?: (object) []]);
        $rows->insert('workflow_bindings', ['project_id' => $id('PY'), 'definition' => (object) []]);
        $rows->insert('workflow_slot_links', ['project_id' => $id('PX'), 'slot_key' => 'implementation', 'column_id' => $id('COLX')]);

        $number = 0;
        foreach (['P', 'C1', 'C2', 'B', 'S', 'U', 'N1', 'W'] as $card) {
            $rows->insert('board_cards', [
                'id' => $id($card),
                'project_id' => $id('PX'),
                'column_id' => $id('COLX'),
                'number' => ++$number,
                'parent_card_id' => \in_array($card, ['C1', 'C2'], true) ? $id('P') : null,
            ]);
        }
        $rows->insert('board_cards', ['id' => $id('Z'), 'project_id' => $id('PY'), 'column_id' => $id('COLY'), 'number' => 1]);
        $rows->insert('board_card_links', ['source_card_id' => $id('B'), 'target_card_id' => $id('C1'), 'kind' => 'blocks']);

        $rows->insert('forge_pull_requests', ['id' => $id('PR1'), 'project_id' => $id('PX'), 'forge' => 'github', 'repository' => 'o/r', 'number' => 1, 'head_branch' => 'feature', 'base_branch' => 'main']);
        $rows->insert('forge_pull_requests', ['id' => $id('PR2'), 'project_id' => $id('PX'), 'forge' => 'github', 'repository' => 'o/r', 'number' => 2, 'head_branch' => 'stacked', 'base_branch' => 'feature']);
        foreach ([['C1', 1], ['S', 2], ['C2', 3]] as [$card, $pullRequest]) {
            $rows->insert('board_card_pull_requests', ['card_id' => $id($card), 'forge' => 'github', 'repository' => 'o/r', 'number' => $pullRequest, 'url' => 'https://github.test/o/r/pull/'.$pullRequest]);
        }

        $rows->insert('board_card_documents', ['card_id' => $id('P'), 'document_id' => $id('D1')]);
        $rows->insert('document_versions', ['id' => $id('DV1'), 'document_id' => $id('D1')]);
        $rows->insert('document_tags', ['document_id' => $id('D1'), 'tag_id' => $id('T1')]);

        $rows->insert('inbox_item_cards', ['item_id' => $id('I1'), 'card_id' => $id('C1')]);
        $rows->insert('inbox_reviews', ['item_id' => $id('I1'), 'document_id' => $id('D1')]);
        $rows->insert('inbox_ask_items', ['ask_id' => $id('A1'), 'item_id' => $id('I1')]);
        $rows->insert('inbox_asks', ['id' => $id('A1'), 'card_id' => $id('N1'), 'project_id' => $id('PX'), 'bridge_id' => $id('BR1'), 'session_id' => $id('SS1')]);

        $rows->insert('bridges', ['id' => $id('BR1'), 'owner_id' => $id('OX'), 'login_id' => $id('LG1')]);
        $rows->insert('bridge_worker_runs', ['id' => $id('R0'), 'project_id' => $id('PX'), 'bridge_id' => $id('BR1'), 'session_id' => $id('SS1'), 'subject_id' => $id('C2')]);
        $rows->insert('bridge_worker_runs', ['id' => $id('R1'), 'project_id' => $id('PX'), 'waits_on_ask_id' => $id('A1'), 'subject_id' => $id('W')]);
        $rows->insert('work_requests', ['id' => $id('WR0'), 'project_id' => $id('PX'), 'subject_type' => 'card', 'subject_id' => $id('C2')]);

        $rows->insert('board_card_verdicts', ['id' => $id('V1'), 'card_id' => $id('C1'), 'notes' => [['id' => $id('K1')]]]);
        $rows->insert('agent_reviews', ['id' => $id('AR0'), 'project_id' => $id('PX'), 'card_id' => $id('C2'), 'pull_request_id' => $id('PR1')]);
        $rows->insert('oauth_refresh_token_login', ['login_id' => $id('LG1'), 'refresh_token_identifier' => self::token(self::TOKEN)]);
        $rows->insert('forge_repositories', ['project_id' => $id('PX'), 'source' => 'installation', 'source_ref' => (string) self::INSTALLATION]);
        $rows->insert('workflow_rule_states', ['card_id' => $id('C1'), 'project_id' => $id('PX'), 'rule_id' => 'world']);

        $db->executeStatement('DELETE FROM workflow_card_changes');
        $db->executeStatement('DELETE FROM workflow_project_changes');

        return $world;
    }

    /** @return array{list<string>, list<string>} the marked card names and project names, sorted */
    public function marks(): array
    {
        $names = self::names();
        $named = static fn (array $ids): array => array_values(array_map(static fn (string $id): string => $names[$id] ?? $id, $ids));
        $cards = $named($this->db->fetchFirstColumn('SELECT card_id FROM workflow_card_changes'));
        $projects = $named($this->db->fetchFirstColumn('SELECT project_id FROM workflow_project_changes'));
        sort($cards);
        sort($projects);

        return [$cards, $projects];
    }

    public function clearMarks(): void
    {
        $this->db->executeStatement('DELETE FROM workflow_card_changes');
        $this->db->executeStatement('DELETE FROM workflow_project_changes');
    }
}
