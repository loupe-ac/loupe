<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_find_latest_session_of_card_answers_the_newest_run_with_a_session(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'latest-session-'.uniqid().'@example.com'), 'Latest session');
        $other = $this->project($em, $this->user($em, 'latest-session-other-'.uniqid().'@example.com'), 'Other');
        $cardId = Uuid::v7();

        $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 10:00:00'), cardId: $cardId);
        $newest = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-02 10:00:00'), cardId: $cardId);
        $noSession = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-03 10:00:00'), cardId: $cardId);
        $noSession->sessionId = null;
        $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-04 10:00:00'));
        $this->seedRun($em, $other, new \DateTimeImmutable('2026-09-05 10:00:00'), cardId: $cardId);
        $em->flush();

        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);

        self::assertSame($newest, $runs->findLatestSessionOfCard($project, $cardId));
        self::assertNull($runs->findLatestSessionOfCard($project, Uuid::v7()));
    }

    public function test_find_latest_run_rows_answers_the_newest_run_of_each_card(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'latest-rows-'.uniqid().'@example.com'), 'Latest rows');
        $other = $this->project($em, $this->user($em, 'latest-rows-other-'.uniqid().'@example.com'), 'Other');
        $closedCard = Uuid::v7();
        $openCard = Uuid::v7();
        $tieCard = Uuid::v7();
        $at = new \DateTimeImmutable('2026-09-02 10:00:00');

        $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 10:00:00'), exitCode: 1, cardId: $closedCard);
        $newest = $this->seedRun($em, $project, $at, output: 'done', cardId: $closedCard);
        $askId = Uuid::v7();
        $newest->waitsOnAskId = $askId;
        $em->flush();
        $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 10:00:00'), cardId: $openCard);
        $open = $this->seedRun($em, $project, $at, cardId: $openCard, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $first = $this->seedRun($em, $project, $at, cardId: $tieCard);
        $second = $this->seedRun($em, $project, $at, cardId: $tieCard);
        $this->seedRun($em, $other, new \DateTimeImmutable('2026-09-09 10:00:00'), cardId: $closedCard);

        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);
        $tieWinner = strcmp((string) $first->id, (string) $second->id) > 0 ? $first : $second;

        $all = $runs->findLatestRunRows($project, null);
        self::assertEqualsCanonicalizing(
            [(string) $newest->id, (string) $open->id, (string) $tieWinner->id],
            array_column($all, 'id'),
        );
        $byId = array_column($all, null, 'id');
        $row = $byId[(string) $newest->id];
        self::assertSame(
            ['id' => (string) $newest->id, 'card_id' => (string) $closedCard, 'state' => WorkerRunState::Succeeded->value, 'output' => 'done', 'waits_on_ask_id' => (string) $askId],
            array_diff_key($row, ['ended_at' => true]),
        );
        self::assertArrayHasKey('ended_at', $row);
        self::assertSame(WorkerRunState::Running->value, $byId[(string) $open->id]['state']);

        self::assertSame(
            [(string) $open->id],
            array_column($runs->findLatestRunRows($project, [$openCard, Uuid::v7()]), 'id'),
        );
        self::assertSame([], $runs->findLatestRunRows($project, []));
    }

    public function test_find_card_ids_with_open_run_answers_the_cards_with_an_open_run_of_any_kind(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'open-runs-'.uniqid().'@example.com'), 'Open runs');
        $other = $this->project($em, $this->user($em, 'open-runs-other-'.uniqid().'@example.com'), 'Other');
        $interactive = Uuid::v7();
        $queued = Uuid::v7();
        $closed = Uuid::v7();
        $elsewhere = Uuid::v7();
        $unasked = Uuid::v7();

        $this->seedRun($em, $project, cardId: $interactive, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $this->seedRun($em, $project, cardId: $interactive, state: WorkerRunState::Resumed);
        $this->seedRun($em, $project, cardId: $queued, state: WorkerRunState::Queued);
        $this->seedRun($em, $project, cardId: $closed, state: WorkerRunState::Succeeded);
        $this->seedRun($em, $other, cardId: $elsewhere, state: WorkerRunState::Running);
        $this->seedRun($em, $project, cardId: $unasked, state: WorkerRunState::Running);
        $em->flush();

        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);

        self::assertEqualsCanonicalizing(
            [$interactive->toRfc4122(), $queued->toRfc4122()],
            $runs->findCardIdsWithOpenRun($project, [$interactive, $queued, $closed, $elsewhere, Uuid::v7()]),
        );
        self::assertSame([], $runs->findCardIdsWithOpenRun($project, []));
    }

    public function test_find_open_work_kinds_of_card_answers_the_distinct_kinds_of_its_open_worker_runs(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'open-kinds-'.uniqid().'@example.com'), 'Open kinds');
        $cardId = Uuid::v7();

        $this->seedRun($em, $project, workKind: 'implement', cardId: $cardId, state: WorkerRunState::Running);
        $this->seedRun($em, $project, workKind: 'breakdown', cardId: $cardId, state: WorkerRunState::Running);
        $this->seedRun($em, $project, workKind: 'breakdown', cardId: $cardId, state: WorkerRunState::Resumed);
        $this->seedRun($em, $project, workKind: 'fix', cardId: $cardId, state: WorkerRunState::Queued);
        $this->seedRun($em, $project, workKind: 'review', cardId: $cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $this->seedRun($em, $project, workKind: 'sync', cardId: $cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Command);
        $this->seedRun($em, $project, workKind: null, cardId: $cardId, state: WorkerRunState::Running);
        $this->seedRun($em, $project, workKind: 'plan', cardId: $cardId, state: WorkerRunState::Succeeded);
        $this->seedRun($em, $project, workKind: 'teardown', state: WorkerRunState::Running);

        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);

        self::assertSame(['breakdown', 'fix', 'implement'], $runs->findOpenWorkKindsOfCard($cardId));
        self::assertSame([], $runs->findOpenWorkKindsOfCard(Uuid::v7()));
    }

    public function test_find_open_work_since_of_card_answers_the_start_of_the_oldest_open_worker_run_of_each_kind(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'open-since-'.uniqid().'@example.com'), 'Open since');
        $cardId = Uuid::v7();

        $this->startedAt($em, $this->seedRun($em, $project, workKind: 'implement', cardId: $cardId, state: WorkerRunState::Running), '2026-01-01 10:20:00');
        $this->startedAt($em, $this->seedRun($em, $project, workKind: 'implement', cardId: $cardId, state: WorkerRunState::Resumed), '2026-01-01 10:10:00');
        $this->startedAt($em, $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-01-01 10:30:00'), workKind: 'fix', cardId: $cardId, state: WorkerRunState::Queued), null);
        $this->seedRun($em, $project, workKind: 'review', cardId: $cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $this->seedRun($em, $project, workKind: 'sync', cardId: $cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Command);
        $this->seedRun($em, $project, workKind: null, cardId: $cardId, state: WorkerRunState::Running);
        $this->seedRun($em, $project, workKind: 'plan', cardId: $cardId, state: WorkerRunState::Succeeded);

        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);

        self::assertEquals(
            ['fix' => new \DateTimeImmutable('2026-01-01 10:30:00'), 'implement' => new \DateTimeImmutable('2026-01-01 10:10:00')],
            $runs->findOpenWorkSinceOfCard($cardId)['all'],
        );
        self::assertSame(['fix', 'implement'], array_keys($runs->findOpenWorkSinceOfCard($cardId)['all']));
        self::assertSame([], $runs->findOpenWorkSinceOfCard(Uuid::v7())['all']);
    }

    public function test_find_open_work_since_of_card_can_leave_out_the_runs_that_a_person_started(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'open-origin-'.uniqid().'@example.com'), 'Open origin');
        $cardId = Uuid::v7();
        $person = $this->seedWorkRequest($em, $project, $cardId, 'resume', state: WorkRequestState::Claimed, ruleId: 'inbox:ask-answer');

        $this->seedRun($em, $project, workKind: 'resume', cardId: $cardId, state: WorkerRunState::Running, workRequestId: $person->id);
        $this->seedRun($em, $project, workKind: 'session', cardId: $cardId, state: WorkerRunState::Running, workRequestId: Uuid::v7(), ruleId: 'inbox:start-session');
        $this->seedRun($em, $project, workKind: 'fix', cardId: $cardId, state: WorkerRunState::Running, workRequestId: Uuid::v7(), ruleId: 'fix-on-entry');
        $this->seedRun($em, $project, workKind: 'implement', cardId: $cardId, state: WorkerRunState::Running);

        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);

        self::assertSame(['fix', 'implement', 'resume', 'session'], array_keys($runs->findOpenWorkSinceOfCard($cardId)['all']));
        self::assertSame(['fix', 'implement'], array_keys($runs->findOpenWorkSinceOfCard($cardId)['ruleOwned']));
    }

    public function test_find_oldest_open_interactive_row_of_card_answers_the_oldest_open_session(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'open-session-'.uniqid().'@example.com'), 'Open session');
        $cardId = Uuid::v7();
        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);

        $quiet = Uuid::v7();
        foreach ([$quiet, $cardId] as $card) {
            $this->seedRun($em, $project, workKind: 'implement', cardId: $card, state: WorkerRunState::Running);
            $this->seedRun($em, $project, workKind: 'review', cardId: $card, state: WorkerRunState::Succeeded, kind: WorkerRunKind::Interactive);
        }
        self::assertNull($runs->findOldestOpenInteractiveRowOfCard($quiet));

        $this->startedAt($em, $this->seedRun($em, $project, workKind: 'later', cardId: $cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive), '2026-01-01 10:20:00');
        $this->startedAt($em, $this->seedRun($em, $project, workKind: null, cardId: $cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive), '2026-01-01 10:10:00');

        $row = $runs->findOldestOpenInteractiveRowOfCard($cardId);
        self::assertNotNull($row);
        self::assertNull($row['work_kind']);
        self::assertEquals(new \DateTimeImmutable('2026-01-01 10:10:00'), new \DateTimeImmutable($row['since']));
    }

    public function test_a_newer_run_of_the_card_hides_its_warning_unless_it_was_set_aside(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'warning-newer-'.uniqid().'@example.com'), 'Warning newer');

        $hiddenByQueued = Uuid::v7();
        $this->warningClosedAt($em, $project, $hiddenByQueued, WorkerRunState::Failed);
        $this->runReceivedAt($em, $project, $hiddenByQueued, WorkerRunState::Queued, '10:06');

        $keptByEarlierOpen = Uuid::v7();
        $this->runReceivedAt($em, $project, $keptByEarlierOpen, WorkerRunState::Running, '10:01');
        $failedLate = $this->warningClosedAt($em, $project, $keptByEarlierOpen, WorkerRunState::Failed);

        $hiddenByInteractive = Uuid::v7();
        $this->warningClosedAt($em, $project, $hiddenByInteractive, WorkerRunState::Blocked);
        $this->runReceivedAt($em, $project, $hiddenByInteractive, WorkerRunState::Running, '10:06', WorkerRunKind::Interactive);

        $hiddenByTimedOut = Uuid::v7();
        $this->warningClosedAt($em, $project, $hiddenByTimedOut, WorkerRunState::GaveUp);
        $this->runReceivedAt($em, $project, $hiddenByTimedOut, WorkerRunState::TimedOut, '10:06');

        $keptByReplaced = Uuid::v7();
        $replacedWarning = $this->warningClosedAt($em, $project, $keptByReplaced, WorkerRunState::NoResult);
        $this->runReceivedAt($em, $project, $keptByReplaced, WorkerRunState::Replaced, '10:06');

        $keptBySkipped = Uuid::v7();
        $skippedWarning = $this->warningClosedAt($em, $project, $keptBySkipped, WorkerRunState::Failed);
        $this->runReceivedAt($em, $project, $keptBySkipped, WorkerRunState::Skipped, '10:06');

        $hiddenInTheSameSecond = Uuid::v7();
        $this->warningClosedAt($em, $project, $hiddenInTheSameSecond, WorkerRunState::Failed);
        $this->runReceivedAt($em, $project, $hiddenInTheSameSecond, WorkerRunState::Queued, '10:05');

        $keptInTheSameSecond = Uuid::v7();
        $this->runReceivedAt($em, $project, $keptInTheSameSecond, WorkerRunState::Queued, '10:05');
        $sameSecondWarning = $this->warningClosedAt($em, $project, $keptInTheSameSecond, WorkerRunState::Failed);

        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);

        self::assertEqualsCanonicalizing(
            [(string) $failedLate->id, (string) $replacedWarning->id, (string) $skippedWarning->id, (string) $sameSecondWarning->id],
            array_column($runs->findWarningRowsOfCards($project, [$hiddenByQueued, $keptByEarlierOpen, $hiddenByInteractive, $hiddenByTimedOut, $keptByReplaced, $keptBySkipped, $hiddenInTheSameSecond, $keptInTheSameSecond]), 'id'),
        );
        self::assertSame([], $runs->findWarningRowsOfCards($project, []));
        // A card outside the list gets no warning, though it has one.
        self::assertSame(
            [(string) $skippedWarning->id],
            array_column($runs->findWarningRowsOfCards($project, [$keptBySkipped, $hiddenByQueued]), 'id'),
        );
        self::assertNull($runs->findWarningRowOfCard($project, $hiddenByQueued));
        self::assertNull($runs->findWarningRowOfCard($project, $hiddenInTheSameSecond));
        self::assertSame(
            ['id' => (string) $failedLate->id, 'card_id' => (string) $keptByEarlierOpen, 'state' => WorkerRunState::Failed->value, 'output' => 'worker output', 'closed_at' => '2026-09-01 10:05:00'],
            $runs->findWarningRowOfCard($project, $keptByEarlierOpen),
        );
    }

    /** A warning run received at 10:00, which the server closed at 10:05. */
    public function test_find_open_by_run_key_answers_only_the_open_worker_run_of_that_launch_on_that_card_in_that_role(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'open-by-key-'.uniqid().'@example.com'), 'Open by key');
        $otherProject = $this->project($em, $this->user($em, 'open-by-key-other-'.uniqid().'@example.com'), 'Other');
        $bridgeId = Uuid::v7();
        $runKey = Uuid::v4();
        $cardId = Uuid::v7();
        $at = new \DateTimeImmutable('2026-09-01 10:00:00');

        $match = $this->seedRun($em, $project, $at, workKind: 'review', bridgeId: $bridgeId, cardId: $cardId, state: WorkerRunState::Resumed, runKey: $runKey, role: 'code-review');
        $closedKey = Uuid::v4();
        $this->seedRun($em, $project, $at, workKind: 'review', bridgeId: $bridgeId, cardId: $cardId, state: WorkerRunState::Succeeded, runKey: $closedKey, role: 'code-review');
        $interactiveKey = Uuid::v4();
        $this->seedRun($em, $project, $at, workKind: 'review', bridgeId: $bridgeId, cardId: $cardId, state: WorkerRunState::Running, runKey: $interactiveKey, kind: WorkerRunKind::Interactive, role: 'code-review');
        $foreignKey = Uuid::v4();
        $this->seedRun($em, $otherProject, $at, workKind: 'review', bridgeId: $bridgeId, cardId: $cardId, state: WorkerRunState::Running, runKey: $foreignKey, role: 'code-review');
        $em->flush();

        $repository = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $repository);

        self::assertSame($match, $repository->findOpenByRunKey($project, $bridgeId, $runKey, $cardId, 'code-review'));
        self::assertNull($repository->findOpenByRunKey($project, $bridgeId, Uuid::v4(), $cardId, 'code-review'), 'another run key');
        self::assertNull($repository->findOpenByRunKey($project, Uuid::v7(), $runKey, $cardId, 'code-review'), 'another bridge');
        self::assertNull($repository->findOpenByRunKey($project, $bridgeId, $runKey, Uuid::v7(), 'code-review'), 'another card');
        self::assertNull($repository->findOpenByRunKey($project, $bridgeId, $runKey, $cardId, 'design-review'), 'another role');
        self::assertNull($repository->findOpenByRunKey($project, $bridgeId, $closedKey, $cardId, 'code-review'), 'a closed run');
        self::assertNull($repository->findOpenByRunKey($project, $bridgeId, $interactiveKey, $cardId, 'code-review'), 'an interactive run');
        self::assertNull($repository->findOpenByRunKey($project, $bridgeId, $foreignKey, $cardId, 'code-review'), 'a run of another project');
        self::assertNull($repository->findOpenByRunKey($otherProject, $bridgeId, $runKey, $cardId, 'code-review'), 'the project of another run');
    }

    public function test_find_worker_by_run_key_answers_the_worker_run_of_that_launch_in_any_state(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'worker-by-key-'.uniqid().'@example.com'), 'Worker by key');
        $otherProject = $this->project($em, $this->user($em, 'worker-by-key-other-'.uniqid().'@example.com'), 'Other');
        $bridgeId = Uuid::v7();
        $at = new \DateTimeImmutable('2026-09-01 10:00:00');
        $openKey = Uuid::v4();
        $closedKey = Uuid::v4();
        $interactiveKey = Uuid::v4();

        $open = $this->seedRun($em, $project, $at, bridgeId: $bridgeId, state: WorkerRunState::Running, runKey: $openKey);
        $closed = $this->seedRun($em, $project, $at, bridgeId: $bridgeId, state: WorkerRunState::Succeeded, runKey: $closedKey);
        $this->seedRun($em, $project, $at, bridgeId: $bridgeId, state: WorkerRunState::Running, runKey: $interactiveKey, kind: WorkerRunKind::Interactive);
        $em->flush();

        $repository = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $repository);

        self::assertSame($open, $repository->findWorkerByRunKey($project, $bridgeId, $openKey));
        self::assertSame($closed, $repository->findWorkerByRunKey($project, $bridgeId, $closedKey));
        self::assertNull($repository->findWorkerByRunKey($project, $bridgeId, Uuid::v4()), 'another run key');
        self::assertNull($repository->findWorkerByRunKey($project, Uuid::v7(), $openKey), 'another bridge');
        self::assertNull($repository->findWorkerByRunKey($project, $bridgeId, $interactiveKey), 'an interactive run');
        self::assertNull($repository->findWorkerByRunKey($otherProject, $bridgeId, $openKey), 'another project');
    }

    private function warningClosedAt(EntityManagerInterface $em, Project $project, Uuid $cardId, WorkerRunState $state): WorkerRun
    {
        $run = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 10:00:00'), cardId: $cardId, state: $state, runKey: Uuid::v4());
        $em->persist(new WorkerRunStateChange($run, $state, new \DateTimeImmutable('2026-09-01 10:05:00'), new \DateTimeImmutable('2026-09-01 10:05:00')));
        $em->flush();

        return $run;
    }

    private function runReceivedAt(EntityManagerInterface $em, Project $project, Uuid $cardId, WorkerRunState $state, string $time, WorkerRunKind $kind = WorkerRunKind::Worker): WorkerRun
    {
        $at = new \DateTimeImmutable('2026-09-01 '.$time.':00');
        $run = $this->seedRun($em, $project, $at, cardId: $cardId, state: $state, kind: $kind);
        $em->persist(new WorkerRunStateChange($run, $state, $at, $at));
        $em->flush();

        return $run;
    }

    private function startedAt(EntityManagerInterface $em, WorkerRun $run, ?string $at): void
    {
        $run->startedAt = null === $at ? null : new \DateTimeImmutable($at);
        $em->flush();
    }
}
