<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunOutputChunk;
use App\Module\Bridge\ValueObject\WorkerRunOutputSlice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerRunOutputChunk>
 */
class WorkerRunOutputChunkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunOutputChunk::class);
    }

    /** The bytes stored for the run, which is also the offset the next append must carry. */
    public function storedLength(WorkerRun $run): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COALESCE(MAX(start_offset + length), 0) FROM bridge_worker_run_output_chunks WHERE run_id = :run',
            ['run' => (string) $run->id],
        );
    }

    /** True when the log hit the size cap, so it takes no more text. */
    public function isCut(WorkerRun $run): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM bridge_worker_run_output_chunks WHERE run_id = :run AND cut)',
            ['run' => (string) $run->id],
        );
    }

    /**
     * The stored text from a byte offset on, at most $maxBytes of it, cut at a
     * character boundary. An offset inside a character moves to the next boundary.
     * When no text lies at the offset, the slice is empty and its length is the
     * smaller of the offset and the stored length.
     */
    public function textFrom(WorkerRun $run, int $byteOffset, int $maxBytes): WorkerRunOutputSlice
    {
        $byteOffset = max(0, $byteOffset);
        $query = $this->getEntityManager()->createQuery(
            'SELECT c.startOffset, c.length, c.text FROM '.WorkerRunOutputChunk::class.' c
             WHERE c.run = :run AND c.startOffset + c.length > :offset ORDER BY c.startOffset ASC',
        )->setParameter('run', $run)->setParameter('offset', $byteOffset);

        $text = '';
        $taken = 0;
        $position = $byteOffset;
        /** @var array{startOffset: int, length: int, text: string} $row */
        foreach ($query->toIterable() as $row) {
            if ($taken >= $maxBytes) {
                break;
            }
            $piece = $row['text'];
            if ($row['startOffset'] < $position) {
                $piece = substr($piece, $position - $row['startOffset']);
                $skipped = 0;
                while ($skipped < \strlen($piece) && 0x80 === (\ord($piece[$skipped]) & 0xC0)) {
                    ++$skipped;
                }
                $piece = substr($piece, $skipped);
                $position += $skipped;
            }
            $whole = \strlen($piece);
            $piece = mb_strcut($piece, 0, $maxBytes - $taken, 'UTF-8');
            $text .= $piece;
            $taken += \strlen($piece);
            $position += \strlen($piece);
            if (\strlen($piece) < $whole) {
                break;
            }
        }

        if ('' === $text) {
            return new WorkerRunOutputSlice('', min($byteOffset, $this->storedLength($run)));
        }

        return new WorkerRunOutputSlice($text, $position);
    }

    /** The count of line breaks stored for the run. */
    public function lineCount(WorkerRun $run): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(char_length(text) - char_length(replace(text, E'\\n', ''))), 0)
             FROM bridge_worker_run_output_chunks WHERE run_id = :run",
            ['run' => (string) $run->id],
        );
    }

    /** The last $lines lines of the stored text. A final line break stays and counts for no line. */
    public function tailText(WorkerRun $run, int $lines): string
    {
        if ($lines <= 0) {
            return '';
        }

        $query = $this->getEntityManager()->createQuery(
            'SELECT c.text FROM '.WorkerRunOutputChunk::class.' c WHERE c.run = :run ORDER BY c.startOffset DESC',
        )->setParameter('run', $run);

        $tail = '';
        /** @var array{text: string} $row */
        foreach ($query->toIterable() as $row) {
            $tail = $row['text'].$tail;
            if (substr_count($tail, "\n") > $lines) {
                break;
            }
        }

        return self::lastLines($tail, $lines);
    }

    /**
     * The tail of each run in one query, for the runs that have stored text.
     *
     * @param list<WorkerRun> $runs
     *
     * @return array<string, string> keyed by the RFC 4122 run id
     */
    public function tailTexts(array $runs, int $lines): array
    {
        if ([] === $runs || $lines <= 0) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT run_id, text FROM (
                 SELECT run_id, start_offset, text,
                        SUM(char_length(text) - char_length(replace(text, E'\\n', ''))) OVER (PARTITION BY run_id ORDER BY start_offset DESC)
                          - (char_length(text) - char_length(replace(text, E'\\n', ''))) AS newer_breaks
                 FROM bridge_worker_run_output_chunks WHERE run_id IN (:runs)
             ) t WHERE newer_breaks <= :lines ORDER BY run_id, start_offset ASC",
            ['runs' => array_map(static fn (WorkerRun $run): string => (string) $run->id, $runs), 'lines' => $lines],
            ['runs' => ArrayParameterType::STRING],
        );

        $joined = [];
        foreach ($rows as $row) {
            $key = (string) $row['run_id'];
            $joined[$key] = ($joined[$key] ?? '').$row['text'];
        }

        return array_map(static fn (string $text): string => self::lastLines($text, $lines), $joined);
    }

    /**
     * The line count, the stored length and the cap state of each run in one query, for the runs that have stored text.
     *
     * @param list<WorkerRun> $runs
     *
     * @return array<string, array{lines: int, length: int, capped: bool}> keyed by the RFC 4122 run id
     */
    public function stats(array $runs): array
    {
        if ([] === $runs) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT run_id, SUM(char_length(text) - char_length(replace(text, E'\\n', ''))) AS lines, MAX(start_offset + length) AS length,
                    BOOL_OR(cut) AS capped
             FROM bridge_worker_run_output_chunks WHERE run_id IN (:runs) GROUP BY run_id",
            ['runs' => array_map(static fn (WorkerRun $run): string => (string) $run->id, $runs)],
            ['runs' => ArrayParameterType::STRING],
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row['run_id']] = ['lines' => (int) $row['lines'], 'length' => (int) $row['length'], 'capped' => (bool) $row['capped']];
        }

        return $stats;
    }

    private static function lastLines(string $tail, int $lines): string
    {
        $body = str_ends_with($tail, "\n") ? substr($tail, 0, -1) : $tail;
        $all = explode("\n", $body);
        if (\count($all) <= $lines) {
            return $tail;
        }

        return implode("\n", \array_slice($all, -$lines)).(str_ends_with($tail, "\n") ? "\n" : '');
    }

    /** All the stored text of the run, in one query. */
    public function fullText(WorkerRun $run): string
    {
        return (string) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COALESCE(string_agg(text, '' ORDER BY start_offset), '') FROM bridge_worker_run_output_chunks WHERE run_id = :run",
            ['run' => (string) $run->id],
        );
    }

    /**
     * The log of each run of the owner's projects as one text, oldest run first.
     *
     * @return iterable<array{project: string, run_key: string|null, length: int|string, capped: bool, text: string}>
     */
    public function joinedByOwner(User $user): iterable
    {
        /** @var iterable<array{project: string, run_key: string|null, length: int|string, capped: bool, text: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->iterateAssociative(
            "SELECT p.name AS project, r.run_key AS run_key, MAX(c.start_offset + c.length) AS length,
                    BOOL_OR(c.cut) AS capped, string_agg(c.text, '' ORDER BY c.start_offset) AS text
             FROM bridge_worker_run_output_chunks c
             JOIN bridge_worker_runs r ON r.id = c.run_id
             JOIN projects p ON p.id = r.project_id
             WHERE p.owner_id = :owner
             GROUP BY r.id, r.run_key, p.name
             ORDER BY r.id ASC",
            ['owner' => (string) $user->id],
        );

        return $rows;
    }
}
