<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Service\BucketFigures;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunBucketTimeRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_gives_a_run_exactly_the_rows_it_is_given_and_a_repeat_changes_nothing(): void
    {
        self::bootKernel();
        $run = $this->seedRun($this->em(), $this->project($this->em(), $this->user($this->em(), 'bucket-repo-'.uniqid().'@example.com'), 'Bucket repo'));
        $runId = $run->id ?? throw new \LogicException('A stored run has an id.');

        $this->repository()->replaceForRun($runId, self::figures(['a' => 1, 'b' => 2]));
        $this->repository()->replaceForRun($runId, self::figures(['b' => 5, '123' => 7]));
        $this->repository()->replaceForRun($runId, self::figures(['b' => 5, '123' => 7]));

        $rows = $this->em()->getConnection()->fetchAllKeyValue('SELECT bucket, ms FROM bridge_worker_run_bucket_times WHERE run_id = :run', ['run' => (string) $runId]);
        ksort($rows);
        self::assertSame(['123' => 7, 'b' => 5], array_map(intval(...), $rows));

        $this->repository()->replaceForRun($runId, self::figures([]));

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_bucket_times WHERE run_id = :run', ['run' => (string) $runId]));
    }

    public function test_the_database_refuses_a_second_row_for_one_run_and_bucket(): void
    {
        self::bootKernel();
        $run = $this->seedRun($this->em(), $this->project($this->em(), $this->user($this->em(), 'bucket-repo-unique-'.uniqid().'@example.com'), 'Bucket unique'));
        $row = ['run_id' => (string) $run->id, 'bucket' => 'a', 'ms' => 1];
        $this->em()->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7()] + $row);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em()->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7()] + $row);
    }

    public function test_it_reads_the_time_of_a_bucket_as_zero_for_a_run_with_other_rows_and_nothing_for_a_run_with_none(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), $this->user($this->em(), 'bucket-repo-read-'.uniqid().'@example.com'), 'Bucket read');
        $both = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $onlyOther = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $none = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $this->repository()->replaceForRun($both, self::figures(['tests' => 40, 'other' => 2]));
        $this->repository()->replaceForRun($onlyOther, self::figures(['other' => 3]));

        $times = $this->repository()->findMillisecondsOfRuns([$both, $onlyOther, $none], 'tests');

        self::assertEquals([(string) $both => 40, (string) $onlyOther => 0], $times);
        self::assertSame([], $this->repository()->findMillisecondsOfRuns([], 'tests'));
    }

    public function test_it_reads_every_bucket_of_the_runs_with_rows_and_nothing_for_a_run_with_none(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), $this->user($this->em(), 'bucket-repo-all-'.uniqid().'@example.com'), 'Bucket all');
        $both = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $one = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $none = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $unasked = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $this->repository()->replaceForRun($both, self::figures(['tests' => 40, 'other' => 2]));
        $this->repository()->replaceForRun($one, self::figures(['git' => 3, '123' => 4]));
        $this->repository()->replaceForRun($unasked, self::figures(['git' => 9]));

        $times = $this->repository()->findAllMillisecondsOfRuns([$both, $one, $none]);
        ksort($times);
        foreach ($times as &$buckets) {
            ksort($buckets);
        }
        unset($buckets);

        $expected = [(string) $both => ['other' => 2, 'tests' => 40], (string) $one => [123 => 4, 'git' => 3]];
        ksort($expected);
        self::assertSame($expected, $times);
        self::assertSame([], $this->repository()->findAllMillisecondsOfRuns([]));
    }

    public function test_it_stores_and_reads_the_calls_and_the_time_with_subagents_of_a_bucket(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), $this->user($this->em(), 'bucket-repo-figures-'.uniqid().'@example.com'), 'Bucket figures');
        $both = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $subagentOnly = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $old = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $none = $this->seedRun($this->em(), $project)->id ?? throw new \LogicException();
        $this->repository()->replaceForRun($both, ['lsp' => new BucketFigures(100, 400, 3), 'other' => new BucketFigures(5, 5, 1)]);
        $this->repository()->replaceForRun($subagentOnly, ['other' => new BucketFigures(5, 5, 1)]);
        $this->em()->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7(), 'run_id' => (string) $old, 'bucket' => 'lsp', 'ms' => 9]);
        $this->em()->getConnection()->insert('bridge_worker_run_bucket_times', ['id' => (string) Uuid::v7(), 'run_id' => (string) $old, 'bucket' => 'other', 'ms' => 1, 'ms_with_subagents' => 1, 'calls' => 1]);
        $runs = [$both, $subagentOnly, $old, $none];

        self::assertEquals([(string) $both => 3, (string) $subagentOnly => 0], $this->repository()->findCallsOfRuns($runs, 'lsp'));
        self::assertEquals([(string) $both => 400, (string) $subagentOnly => 0], $this->repository()->findMillisecondsWithSubagentsOfRuns($runs, 'lsp'));
        // The old row holds no figure, so the whole run reads no data, also in a bucket whose row is complete.
        self::assertArrayNotHasKey((string) $old, $this->repository()->findCallsOfRuns($runs, 'other'));
        self::assertSame([], $this->repository()->findCallsOfRuns([], 'lsp'));
    }

    public function test_the_rows_go_with_the_run(): void
    {
        self::bootKernel();
        $run = $this->seedRun($this->em(), $this->project($this->em(), $this->user($this->em(), 'bucket-repo-cascade-'.uniqid().'@example.com'), 'Bucket cascade'));
        $runId = $run->id ?? throw new \LogicException();
        $this->repository()->replaceForRun($runId, self::figures(['a' => 1]));

        $this->em()->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = :id', ['id' => (string) $runId]);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_bucket_times WHERE run_id = :run', ['run' => (string) $runId]));
    }

    private function repository(): WorkerRunBucketTimeRepository
    {
        $repository = self::getContainer()->get(WorkerRunBucketTimeRepository::class);
        self::assertInstanceOf(WorkerRunBucketTimeRepository::class, $repository);

        return $repository;
    }
}
