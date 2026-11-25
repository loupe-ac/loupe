<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Mark;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Writes one row with plain SQL. It fills each required column that the caller leaves out, and it drops the
 * foreign keys of the tables it touches, so a row needs no parents. The test transaction rolls the drops back.
 */
final class RawRows
{
    /** Values that the entities read back as enums, so a random string would break their hydration. */
    private const array VALID = [
        'board_cards' => ['type' => 'feature', 'origin' => 'human'],
        'forge_pull_requests' => ['state' => 'open', 'checks' => 'pending', 'mergeability' => 'unknown', 'review' => 'none'],
        'work_requests' => ['state' => 'open'],
        'inbox_items' => ['state' => 'open', 'kind' => 'question', 'origin' => 'agent'],
        'bridge_worker_runs' => ['state' => 'running', 'subject_type' => 'card'],
        'board_card_links' => ['kind' => 'blocks'],
        'board_card_events' => ['kind' => 'moved', 'actor_kind' => 'human'],
        'board_card_values' => ['writer_kind' => 'human'],
        'board_card_verdicts' => ['kind' => 'approved'],
        'board_card_verdict_deliveries' => ['state' => 'pending'],
        'card_pauses' => ['kind' => 'rule'],
        'documents' => ['status' => 'draft'],
        'reviews' => ['verdict' => 'approved'],
        'agent_reviews' => ['conclusion' => 'passed'],
        'document_agent_reviews' => ['conclusion' => 'passed'],
        'board_pull_request_comments' => ['state' => 'pending', 'forge' => 'github'],
        'board_pull_request_notices' => ['state' => 'pending', 'forge' => 'github'],
        'bridge_worker_run_states' => ['state' => 'running'],
        'site_review_comments' => ['status' => 'open', 'surface' => 'web'],
        'inbox_reviews' => ['target_kind' => 'document'],
    ];

    /** @var array<string, true> */
    private array $unbound = [];

    /** @var array<string, list<array{name: string, type: string, required: bool}>> */
    private array $columns = [];

    /** @var array<string, list<string>> */
    private array $keys = [];

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed> the primary key of the row
     */
    public function insert(string $table, array $values): array
    {
        $this->unbind($table);
        $row = [];
        foreach ($this->columnsOf($table) as $column) {
            if (\array_key_exists($column['name'], $values)) {
                $row[$column['name']] = $values[$column['name']];
            } elseif ($column['required']) {
                $row[$column['name']] = self::VALID[$table][$column['name']] ?? self::dummy($column['type']);
            }
        }
        $unknown = array_diff(array_keys($values), array_column($this->columnsOf($table), 'name'));
        if ([] !== $unknown) {
            throw new \LogicException(\sprintf('The table %s has no column %s.', $table, implode(', ', $unknown)));
        }
        $types = array_column($this->columnsOf($table), 'type', 'name');
        $key = $this->keyOf($table);
        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
            $table,
            implode(', ', array_map(self::quote(...), array_keys($row))),
            implode(', ', array_map(static fn (string $name): string => \sprintf('CAST(? AS %s)', $types[$name]), array_keys($row))),
            implode(', ', array_map(self::quote(...), $key)),
        );

        return $this->db->fetchAssociative($sql, array_map(self::scalar(...), array_values($row))) ?: throw new \LogicException('An insert returns its key.');
    }

    /**
     * @param array<string, mixed> $key
     * @param array<string, mixed> $set
     */
    public function update(string $table, array $key, array $set): void
    {
        $this->unbind($table);
        $types = array_column($this->columnsOf($table), 'type', 'name');
        $sql = \sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', array_map(static fn (string $name): string => \sprintf('%s = CAST(? AS %s)', self::quote($name), $types[$name]), array_keys($set))),
            implode(' AND ', array_map(static fn (string $name): string => \sprintf('%s = CAST(? AS %s)', self::quote($name), $types[$name]), array_keys($key))),
        );
        self::expectOne($this->db->executeStatement($sql, array_map(self::scalar(...), [...array_values($set), ...array_values($key)])), 'update', $table);
    }

    /** @param array<string, mixed> $key */
    public function rewrite(string $table, array $key, string $column): void
    {
        $this->unbind($table);
        $keyColumns = array_keys($key);
        $sql = \sprintf(
            'UPDATE %1$s SET %2$s = %2$s WHERE %3$s',
            $table,
            self::quote($column),
            implode(' AND ', array_map(fn (string $name): string => \sprintf('%s = CAST(? AS %s)', self::quote($name), array_column($this->columnsOf($table), 'type', 'name')[$name]), $keyColumns)),
        );
        self::expectOne($this->db->executeStatement($sql, array_map(self::scalar(...), array_values($key))), 'rewrite', $table);
    }

    /** @param array<string, mixed> $key */
    public function delete(string $table, array $key): void
    {
        $this->unbind($table);
        $types = array_column($this->columnsOf($table), 'type', 'name');
        $sql = \sprintf(
            'DELETE FROM %s WHERE %s',
            $table,
            implode(' AND ', array_map(static fn (string $name): string => \sprintf('%s = CAST(? AS %s)', self::quote($name), $types[$name]), array_keys($key))),
        );
        self::expectOne($this->db->executeStatement($sql, array_map(self::scalar(...), array_values($key))), 'delete', $table);
    }

    private static function expectOne(int|string $affected, string $operation, string $table): void
    {
        if (1 !== $affected) {
            throw new \LogicException(\sprintf('The %s of %s changed %s rows, not one.', $operation, $table, $affected));
        }
    }

    private function unbind(string $table): void
    {
        if (isset($this->unbound[$table])) {
            return;
        }
        $this->unbound[$table] = true;
        $constraints = $this->db->fetchAllAssociative(
            "SELECT conrelid::regclass::text AS owner, conname FROM pg_constraint WHERE contype = 'f' AND (conrelid = CAST(? AS regclass) OR confrelid = CAST(? AS regclass))",
            [$table, $table],
        );
        foreach ($constraints as $constraint) {
            $this->db->executeStatement(\sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $constraint['owner'], $constraint['conname']));
        }
    }

    /** @return list<array{name: string, type: string, required: bool}> */
    private function columnsOf(string $table): array
    {
        return $this->columns[$table] ??= array_map(
            static fn (array $row): array => ['name' => (string) $row['name'], 'type' => (string) $row['type'], 'required' => (bool) $row['required']],
            $this->db->fetchAllAssociative(
                'SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type,
                    (a.attnotnull AND a.attgenerated = \'\' AND a.attidentity = \'\' AND (NOT a.atthasdef OR pg_get_expr(d.adbin, d.adrelid) LIKE \'NULL::%\')) AS required
                 FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                 WHERE a.attrelid = CAST(? AS regclass) AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum',
                [$table],
            ),
        );
    }

    /** @return list<string> */
    private function keyOf(string $table): array
    {
        return $this->keys[$table] ??= $this->db->fetchFirstColumn(
            'SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey)
             WHERE i.indrelid = CAST(? AS regclass) AND i.indisprimary ORDER BY a.attnum',
            [$table],
        );
    }

    private static function dummy(string $type): mixed
    {
        return match (true) {
            'uuid' === $type => Uuid::v4()->toRfc4122(),
            str_starts_with($type, 'character'), 'text' === $type => bin2hex(random_bytes(3)),
            str_starts_with($type, 'timestamp'), 'date' === $type => '2026-01-01 00:00:00',
            'boolean' === $type => false,
            \in_array($type, ['integer', 'bigint', 'smallint', 'double precision'], true) => 0,
            'json' === $type, 'jsonb' === $type => '[]',
            default => throw new \LogicException(\sprintf('No dummy value for the column type %s.', $type)),
        };
    }

    private static function scalar(mixed $value): mixed
    {
        return match (true) {
            \is_array($value), $value instanceof \stdClass => json_encode($value, \JSON_THROW_ON_ERROR),
            \is_bool($value) => $value ? 'true' : 'false',
            $value instanceof Uuid => $value->toRfc4122(),
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => $value,
        };
    }

    private static function quote(string $name): string
    {
        return '"'.$name.'"';
    }
}
