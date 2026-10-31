<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\EventListener;

use App\Module\Bridge\Entity\WorkerRunBilledCost;
use App\Module\Bridge\Event\BilledCostAwaited;
use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Module\OpenRouter\EventListener\ReadBilledCostOnAwaited;
use App\Module\OpenRouter\Messenger\ReadBilledCost;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class ReadBilledCostOnAwaitedTest extends KernelTestCase
{
    use OpenRouterScenario;

    public function test_a_project_with_no_key_records_no_key_at_once_and_dispatches_no_read(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $project = $this->makeProject($container, $this->makeUser($container, 'awaited-no-key'));
        $row = $this->billedCost($project);

        $this->listener()(new BilledCostAwaited($project->requireId(), $row->runId));

        $this->em()->clear();
        $stored = $this->em()->find(WorkerRunBilledCost::class, $row->runId) ?? throw new \LogicException('The row exists.');
        self::assertSame(BilledCostState::Unknown, $stored->state);
        self::assertSame(BilledCostReason::NoKey, $stored->reason);
        self::assertSame([], $this->reads());
    }

    public function test_the_account_key_of_the_owner_counts(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $owner = $this->makeUser($container, 'awaited-account');
        $this->makeKey($container, $owner);
        $project = $this->makeProject($container, $owner);
        $row = $this->billedCost($project);

        $this->listener()(new BilledCostAwaited($project->requireId(), $row->runId));

        self::assertSame(BilledCostState::Pending, $row->state);
        self::assertCount(1, $this->reads());
    }

    public function test_a_project_with_a_key_dispatches_one_delayed_read_and_makes_no_call(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $project = $this->makeProject($container, $this->makeUser($container, 'awaited-key'));
        $this->makeKey($container, $project);
        $row = $this->billedCost($project);

        $this->listener()(new BilledCostAwaited($project->requireId(), $row->runId));

        self::assertSame(BilledCostState::Pending, $row->state);
        $reads = $this->reads();
        self::assertCount(1, $reads);
        $message = $reads[0]->getMessage();
        self::assertInstanceOf(ReadBilledCost::class, $message);
        self::assertSame((string) $row->runId, $message->runId);
        self::assertSame(120_000, $reads[0]->last(DelayStamp::class)?->getDelay());
    }

    public function test_a_project_that_is_gone_does_nothing(): void
    {
        self::bootKernel();

        $this->listener()(new BilledCostAwaited(Uuid::v7(), Uuid::v7()));

        self::assertSame([], $this->reads());
    }

    private function billedCost(\App\Module\Project\Entity\Project $project): WorkerRunBilledCost
    {
        $row = new WorkerRunBilledCost(Uuid::v7(), Uuid::v4(), $project, 'card', Uuid::v7(), new \DateTimeImmutable('2026-10-30 10:00:00'));
        $this->em()->persist($row);
        $this->em()->flush();

        return $row;
    }

    /** @return list<\Symfony\Component\Messenger\Envelope> */
    private function reads(): array
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter($transport->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof ReadBilledCost));
    }

    private function listener(): ReadBilledCostOnAwaited
    {
        $listener = self::getContainer()->get(ReadBilledCostOnAwaited::class);
        self::assertInstanceOf(ReadBilledCostOnAwaited::class, $listener);

        return $listener;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
