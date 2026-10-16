<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\AppendWorkerRunOutputCommand;
use App\Module\Bridge\Command\AppendWorkerRunOutputHandler;
use App\Module\Bridge\Command\AppendWorkerRunOutputResult;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunOutputChunkRepository;
use App\Module\Bridge\ValueObject\WorkerRunOutputLog;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AppendWorkerRunOutputHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private User $owner;
    private Project $project;
    private Uuid $bridgeId;
    private Uuid $runKey;
    private WorkerRun $run;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->owner = $this->user($this->em(), 'append-output-'.uniqid().'@example.com');
        $this->project = $this->project($this->em(), $this->owner, 'Append output');
        $this->bridgeId = Uuid::v7();
        $this->runKey = Uuid::v4();
        $this->run = $this->seedRun($this->em(), $this->project, bridgeId: $this->bridgeId, runKey: $this->runKey);
    }

    public function test_text_at_the_end_of_the_log_is_stored(): void
    {
        $first = $this->append(0, "one\n");
        $second = $this->append(4, "two\n");

        self::assertSame([4, false], [$first->length, $first->capped]);
        self::assertSame([8, false], [$second->length, $second->capped]);
        self::assertSame("one\ntwo\n", $this->chunks()->fullText($this->run));
        self::assertSame(8, $this->chunks()->storedLength($this->run));
    }

    public function test_text_at_another_offset_is_dropped_and_the_answer_names_the_end(): void
    {
        $this->append(0, 'abcd');

        $repeat = $this->append(0, 'abcd');
        $gap = $this->append(9, 'zzzz');

        self::assertSame(4, $repeat->length);
        self::assertSame(4, $gap->length);
        self::assertFalse($gap->capped);
        self::assertSame('abcd', $this->chunks()->fullText($this->run));
    }

    public function test_an_empty_text_stores_nothing(): void
    {
        $result = $this->append(0, '');

        self::assertTrue($result->runFound);
        self::assertSame(0, $result->length);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_output_chunks'));
    }

    public function test_the_cap_cuts_a_multibyte_character_whole(): void
    {
        $this->append(0, str_repeat('a', WorkerRunOutputLog::MAX_BYTES - 5));

        // Four 3-byte characters: only one fits the 5 bytes left, as 3 bytes.
        $result = $this->append(WorkerRunOutputLog::MAX_BYTES - 5, str_repeat('€', 4));

        self::assertSame([WorkerRunOutputLog::MAX_BYTES - 2, true], [$result->length, $result->capped]);
        self::assertTrue($this->chunks()->isCut($this->run));
        $tail = $this->chunks()->textFrom($this->run, WorkerRunOutputLog::MAX_BYTES - 5, 100);
        self::assertSame('€', $tail->text);
        self::assertTrue(mb_check_encoding($this->chunks()->fullText($this->run), 'UTF-8'));
    }

    public function test_a_log_that_ends_at_the_cap_is_cut_by_the_next_byte(): void
    {
        $full = $this->append(0, str_repeat('a', WorkerRunOutputLog::MAX_BYTES));
        self::assertSame([WorkerRunOutputLog::MAX_BYTES, false], [$full->length, $full->capped]);

        $next = $this->append(WorkerRunOutputLog::MAX_BYTES, 'b');

        self::assertSame([WorkerRunOutputLog::MAX_BYTES, true], [$next->length, $next->capped]);
        self::assertTrue($this->chunks()->isCut($this->run));
    }

    public function test_a_cut_log_takes_nothing_more(): void
    {
        $this->append(0, str_repeat('a', WorkerRunOutputLog::MAX_BYTES + 10));

        $after = $this->append(WorkerRunOutputLog::MAX_BYTES, 'more');

        self::assertSame([WorkerRunOutputLog::MAX_BYTES, true], [$after->length, $after->capped]);
        self::assertSame(WorkerRunOutputLog::MAX_BYTES, $this->chunks()->storedLength($this->run));
    }

    public function test_a_run_of_another_bridge_is_not_found_and_stores_nothing(): void
    {
        $result = ($this->handler())(new AppendWorkerRunOutputCommand($this->owner, (string) $this->project->id, $this->runKey, Uuid::v7(), 0, 'text'));

        self::assertTrue($result->projectFound);
        self::assertFalse($result->runFound);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_output_chunks'));
    }

    public function test_a_stranger_does_not_find_the_project(): void
    {
        $stranger = $this->user($this->em(), 'append-output-stranger-'.uniqid().'@example.com');

        $result = ($this->handler())(new AppendWorkerRunOutputCommand($stranger, (string) $this->project->id, $this->runKey, $this->bridgeId, 0, 'text'));

        self::assertFalse($result->projectFound);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_output_chunks'));
    }

    public function test_an_unknown_run_is_not_found(): void
    {
        $result = ($this->handler())(new AppendWorkerRunOutputCommand($this->owner, (string) $this->project->id, Uuid::v4(), $this->bridgeId, 0, 'text'));

        self::assertTrue($result->projectFound);
        self::assertFalse($result->runFound);
    }

    private function append(int $offset, string $text): AppendWorkerRunOutputResult
    {
        return ($this->handler())(new AppendWorkerRunOutputCommand($this->owner, (string) $this->project->id, $this->runKey, $this->bridgeId, $offset, $text));
    }

    private function handler(): AppendWorkerRunOutputHandler
    {
        $handler = self::getContainer()->get(AppendWorkerRunOutputHandler::class);
        self::assertInstanceOf(AppendWorkerRunOutputHandler::class, $handler);

        return $handler;
    }

    private function chunks(): WorkerRunOutputChunkRepository
    {
        $chunks = self::getContainer()->get(WorkerRunOutputChunkRepository::class);
        self::assertInstanceOf(WorkerRunOutputChunkRepository::class, $chunks);

        return $chunks;
    }
}
