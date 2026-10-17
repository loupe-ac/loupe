<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunOutputChunkRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkerRunOutputChunkRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    private WorkerRun $run;

    protected function setUp(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'output-repo-'.uniqid().'@example.com');
        $this->run = $this->seedRun($this->em(), $this->project($this->em(), $owner, 'Output repo'));
    }

    public function test_an_empty_log_has_no_length_no_cut_no_lines_and_no_text(): void
    {
        self::assertSame(0, $this->repository()->storedLength($this->run));
        self::assertFalse($this->repository()->isCut($this->run));
        self::assertSame(0, $this->repository()->lineCount($this->run));
        self::assertSame('', $this->repository()->fullText($this->run));
        self::assertSame('', $this->repository()->tailText($this->run, 5));
        $slice = $this->repository()->textFrom($this->run, 10, 100);
        self::assertSame(['', 0], [$slice->text, $slice->length]);
    }

    public function test_the_length_the_text_and_the_lines_span_all_chunks(): void
    {
        $this->seedOutputChunk($this->run, "a€\nb");
        $this->seedOutputChunk($this->run, "c\nd\n");

        self::assertSame(10, $this->repository()->storedLength($this->run));
        self::assertSame("a€\nbc\nd\n", $this->repository()->fullText($this->run));
        self::assertSame(3, $this->repository()->lineCount($this->run));
        self::assertFalse($this->repository()->isCut($this->run));
    }

    public function test_a_cut_chunk_marks_the_log(): void
    {
        $this->seedOutputChunk($this->run, 'abc');
        $this->seedOutputChunk($this->run, 'def', cut: true);

        self::assertTrue($this->repository()->isCut($this->run));
    }

    public function test_text_from_an_offset_runs_across_chunks_and_reports_the_new_length(): void
    {
        $this->seedOutputChunk($this->run, 'abcd');
        $this->seedOutputChunk($this->run, 'efgh');

        $all = $this->repository()->textFrom($this->run, 0, 100);
        $middle = $this->repository()->textFrom($this->run, 2, 100);
        $capped = $this->repository()->textFrom($this->run, 2, 4);
        $end = $this->repository()->textFrom($this->run, 8, 100);
        $past = $this->repository()->textFrom($this->run, 50, 100);

        self::assertSame(['abcdefgh', 8], [$all->text, $all->length]);
        self::assertSame(['cdefgh', 8], [$middle->text, $middle->length]);
        self::assertSame(['cdef', 6], [$capped->text, $capped->length]);
        self::assertSame(['', 8], [$end->text, $end->length]);
        self::assertSame(['', 8], [$past->text, $past->length]);
    }

    public function test_text_from_never_splits_a_character(): void
    {
        $this->seedOutputChunk($this->run, 'a€b');

        $short = $this->repository()->textFrom($this->run, 0, 3);
        $inside = $this->repository()->textFrom($this->run, 2, 100);

        self::assertSame(['a', 1], [$short->text, $short->length]);
        self::assertSame(['b', 5], [$inside->text, $inside->length]);
    }

    public function test_the_tail_is_the_last_lines_across_chunks(): void
    {
        $this->seedOutputChunk($this->run, "one\ntw");
        $this->seedOutputChunk($this->run, "o\nthree\n");
        $this->seedOutputChunk($this->run, 'four');

        self::assertSame('four', $this->repository()->tailText($this->run, 1));
        self::assertSame("three\nfour", $this->repository()->tailText($this->run, 2));
        self::assertSame("one\ntwo\nthree\nfour", $this->repository()->tailText($this->run, 10));
    }

    public function test_a_final_line_break_counts_for_no_line(): void
    {
        $this->seedOutputChunk($this->run, "one\ntwo\nthree\n");

        self::assertSame("three\n", $this->repository()->tailText($this->run, 1));
        self::assertSame("two\nthree\n", $this->repository()->tailText($this->run, 2));
        self::assertSame('', $this->repository()->tailText($this->run, 0));
    }

    public function test_another_runs_chunks_stay_out(): void
    {
        $other = $this->seedRun($this->em(), $this->run->project);
        $this->seedOutputChunk($other, 'other');
        $this->seedOutputChunk($this->run, 'mine');

        self::assertSame('mine', $this->repository()->fullText($this->run));
        self::assertSame(4, $this->repository()->storedLength($this->run));
    }

    public function test_the_database_removes_the_chunks_with_the_run(): void
    {
        $this->seedOutputChunk($this->run, 'gone');
        $runId = (string) $this->run->id;

        $this->em()->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = :id', ['id' => $runId]);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_output_chunks WHERE run_id = :id', ['id' => $runId]));
    }

    public function test_the_retention_sweep_removes_the_chunks_of_an_expired_run(): void
    {
        $old = $this->seedRun($this->em(), $this->run->project, new \DateTimeImmutable('2020-01-01'));
        $this->seedOutputChunk($old, 'old');
        $oldId = (string) $old->id;

        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);
        self::assertGreaterThanOrEqual(1, $runs->deleteReceivedBefore(new \DateTimeImmutable('2021-01-01')));

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_output_chunks WHERE run_id = :id', ['id' => $oldId]));
    }

    private function repository(): WorkerRunOutputChunkRepository
    {
        $repository = self::getContainer()->get(WorkerRunOutputChunkRepository::class);
        self::assertInstanceOf(WorkerRunOutputChunkRepository::class, $repository);

        return $repository;
    }
}
