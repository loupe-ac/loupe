<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunUsageRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_card_total_skips_the_usage_of_another_subject_with_the_same_id(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'usage-subject@example.com'), 'Usage Subject');
        $sharedId = Uuid::v7();
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $sharedId), inputTokens: 100);
        $em->persist(new WorkerRunUsage(null, $project, 'analysis', $sharedId, 'analysis', 'claude-haiku', WorkerRunUsageSource::Reported, 5000, 0, 0, 0, '1.000000'));
        $em->flush();

        $sum = $this->repository()->sumForCard($project, $sharedId);

        self::assertSame(1, $sum['rows']);
        self::assertSame(100, $sum['input']);
        self::assertSame('0.012345', $sum['cost']);
    }

    public function test_a_billed_run_adds_its_billed_cost_and_marks_nothing_estimated(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'usage-billed@example.com'), 'Usage Billed');
        $cardId = Uuid::v7();
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $cardId, runKey: Uuid::v4()), costUsd: '0.100000');
        $billedRun = $this->seedRun($em, $project, cardId: $cardId, runKey: Uuid::v4());
        $this->seedUsage($em, $billedRun, 'deepseek', costUsd: null, inputTokens: 50, billedCost: true);
        $this->seedBilledCost($em, $billedRun, costUsd: '0.500000');

        $sum = $this->repository()->sumForCard($project, $cardId);

        self::assertSame('0.600000', $sum['cost']);
        self::assertSame(150, $sum['input']);
        self::assertFalse($sum['estimated']);
        self::assertSame(0, $sum['pendingRuns']);
        self::assertSame(0, $sum['noCostRuns']);
    }

    public function test_the_card_total_counts_pending_and_unknown_billed_runs(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'usage-billed-open@example.com'), 'Usage Billed Open');
        $cardId = Uuid::v7();
        foreach ([[BilledCostState::Pending, BilledCostReason::NoAnswer], [BilledCostState::Pending, null], [BilledCostState::Unknown, BilledCostReason::NoKey]] as $n => [$state, $reason]) {
            $run = $this->seedRun($em, $project, cardNumber: $n + 1, cardId: $cardId, runKey: Uuid::v4());
            $this->seedUsage($em, $run, 'deepseek', costUsd: null, billedCost: true);
            $this->seedBilledCost($em, $run, $state, null, $reason);
        }

        $sum = $this->repository()->sumForCard($project, $cardId);

        self::assertNull($sum['cost']);
        self::assertSame(2, $sum['pendingRuns']);
        self::assertSame(1, $sum['noCostRuns']);
        self::assertFalse($sum['estimated']);
    }

    public function test_an_unpriced_usage_row_of_an_unbilled_run_still_marks_the_total_estimated(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'usage-unpriced@example.com'), 'Usage Unpriced');
        $cardId = Uuid::v7();
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $cardId), costUsd: null);

        self::assertTrue($this->repository()->sumForCard($project, $cardId)['estimated']);
    }

    private function repository(): WorkerRunUsageRepository
    {
        $repository = self::getContainer()->get(WorkerRunUsageRepository::class);
        self::assertInstanceOf(WorkerRunUsageRepository::class, $repository);

        return $repository;
    }
}
