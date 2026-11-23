<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerWaitRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $this->project = $this->project($em, $this->user($em, 'waits-'.uniqid().'@example.com'), 'Waits');
    }

    public function test_the_open_wait_of_a_card_is_found_and_an_ended_one_is_not(): void
    {
        $cardId = Uuid::v7();
        $open = $this->wait($cardId);
        $other = $this->wait(Uuid::v7());
        $other->end(WorkerWaitEndReason::TimedOut, new \DateTimeImmutable('2026-09-23 11:00:00'));
        $this->em()->flush();

        self::assertSame($open->id?->toRfc4122(), $this->waits()->findOpenOfCard($cardId)?->id?->toRfc4122());
        self::assertNull($this->waits()->findOpenOfCard($other->cardId));
        self::assertNull($this->waits()->findOpenOfCard(Uuid::v7()));
    }

    public function test_a_card_holds_one_open_wait_and_a_new_one_can_follow_an_ended_one(): void
    {
        $cardId = Uuid::v7();
        $first = $this->wait($cardId);
        $first->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('2026-09-23 10:30:00'));
        $this->em()->flush();

        $second = $this->wait($cardId);

        self::assertNotNull($second->id);
        self::assertSame(2, $this->waitCount());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->wait($cardId);
    }

    public function test_the_wait_of_a_run_that_awaits_its_resume_is_found(): void
    {
        $runId = Uuid::v7();
        $open = $this->wait(Uuid::v7(), $runId);
        self::assertSame($open->id?->toRfc4122(), $this->waits()->findAwaitingResumeOfRun($runId)?->id?->toRfc4122());

        $open->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable('2026-09-23 10:30:00'));
        $this->em()->flush();
        self::assertNotNull($this->waits()->findAwaitingResumeOfRun($runId));

        $open->resumeRunId = Uuid::v7();
        $this->em()->flush();
        self::assertNull($this->waits()->findAwaitingResumeOfRun($runId));
    }

    public function test_a_wait_that_ended_without_a_resume_is_not_found_for_its_run(): void
    {
        $runId = Uuid::v7();
        $wait = $this->wait(Uuid::v7(), $runId);
        $wait->end(WorkerWaitEndReason::CardMoved, new \DateTimeImmutable('2026-09-23 10:30:00'));
        $this->em()->flush();

        self::assertNull($this->waits()->findAwaitingResumeOfRun($runId));
    }

    public function test_the_chain_counts_its_own_waits_open_or_ended(): void
    {
        $root = Uuid::v7();
        $this->wait(Uuid::v7(), chainRoot: $root)->end(WorkerWaitEndReason::TimedOut, new \DateTimeImmutable('2026-09-23 10:30:00'));
        $this->wait(Uuid::v7(), chainRoot: $root);
        $this->wait(Uuid::v7(), chainRoot: Uuid::v7());
        $this->em()->flush();

        self::assertSame(2, $this->waits()->countOfChain($root));
        self::assertSame(0, $this->waits()->countOfChain(Uuid::v7()));
    }

    public function test_the_waits_of_a_project_go_with_it_and_other_projects_keep_theirs(): void
    {
        $em = $this->em();
        $other = $this->project($em, $this->project->owner, 'Kept');
        $this->wait(Uuid::v7());
        $kept = new WorkerWait($other, Uuid::v7(), Uuid::v7(), Uuid::v7(), Uuid::v7(), Uuid::v4(), [], new \DateTimeImmutable('2026-09-23 10:00:00'), new \DateTimeImmutable('2026-09-23 11:00:00'));
        $em->persist($kept);
        $em->flush();

        self::assertSame(1, $this->waits()->deleteOfProject($this->project));

        $em->clear();
        self::assertSame(0, $this->waitCount());
        self::assertNotNull($em->find(WorkerWait::class, $kept->id));
    }

    public function test_the_conditions_come_back_as_stored(): void
    {
        $conditions = [['key' => 'pr.checks_passed', 'params' => ['a' => 1], 'pullRequestId' => Uuid::v7()->toRfc4122(), 'token' => 'pr:a/b#1']];
        $wait = $this->wait(Uuid::v7(), conditions: $conditions);
        $id = $wait->id;
        $this->em()->clear();

        self::assertEquals($conditions, $this->em()->find(WorkerWait::class, $id)?->conditions);
    }

    private function waits(): WorkerWaitRepository
    {
        $repository = self::getContainer()->get(WorkerWaitRepository::class);
        self::assertInstanceOf(WorkerWaitRepository::class, $repository);

        return $repository;
    }

    private function waitCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_waits WHERE project_id = ?', [$this->project->requireId()->toRfc4122()]);
    }

    /** @param list<array{key: string, params: array<string, mixed>, pullRequestId: ?string, token: ?string}> $conditions */
    private function wait(Uuid $cardId, ?Uuid $runId = null, ?Uuid $chainRoot = null, array $conditions = []): WorkerWait
    {
        $opened = new \DateTimeImmutable('2026-09-23 10:00:00');
        $wait = new WorkerWait($this->project, $cardId, $runId ?? Uuid::v7(), $chainRoot ?? Uuid::v7(), Uuid::v7(), Uuid::v4(), $conditions, $opened, $opened->modify('+1 hour'));
        $this->em()->persist($wait);
        $this->em()->flush();

        return $wait;
    }
}
