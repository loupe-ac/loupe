<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Scheduler;

use App\Module\Insights\Messenger\RefreshProjectInsights;
use App\Module\Insights\Repository\InsightsProjectActivityRepository;
use App\Module\Insights\Scheduler\SweepInsightsTask;
use App\Module\Insights\Service\InsightsDataKind;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SweepInsightsTaskTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->transport()->reset();
    }

    public function test_it_queues_one_sweep_for_each_awake_project_with_a_stale_item(): void
    {
        $awake = $this->scenarioProject('task-awake');
        $this->seedSavedGraph($awake);
        $dormant = $this->scenarioProject('task-dormant');
        $this->seedSavedGraph($dormant);
        $repository = self::getContainer()->get(InsightsProjectActivityRepository::class);
        self::assertInstanceOf(InsightsProjectActivityRepository::class, $repository);
        $repository->stampView((string) $awake->id, new \DateTimeImmutable());
        $repository->stampView((string) $dormant->id, new \DateTimeImmutable('-30 days'));

        $task = self::getContainer()->get(SweepInsightsTask::class);
        self::assertInstanceOf(SweepInsightsTask::class, $task);
        $task();
        $task();

        $projects = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(RefreshProjectInsights::class, $message);
            self::assertSame(InsightsDataKind::Sweep->value, $message->kind);
            $projects[] = $message->projectId;
        }
        self::assertContains((string) $awake->id, $projects);
        self::assertNotContains((string) $dormant->id, $projects);
        self::assertSame(1, \count(array_keys($projects, (string) $awake->id, true)));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.insights');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
