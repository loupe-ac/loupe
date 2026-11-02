<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Bridge\Entity\WorkerRunBilledCost;
use App\Module\Bridge\Repository\WorkerRunBilledCostRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Module\Inbox\Service\Dev\DevOpenRouterRunSeeder;
use App\Module\Project\Service\Dev\DevOpenRouterProjectSeeder;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevOpenRouterRunSeederTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_each_run_has_a_billed_usage_row_and_a_final_billed_cost_row(): void
    {
        $this->seed();

        $costs = self::getContainer()->get(WorkerRunBilledCostRepository::class)->findAll();
        self::assertCount(4, $costs);
        $states = array_map(static fn (WorkerRunBilledCost $cost): BilledCostState => $cost->state, $costs);
        self::assertNotContains(BilledCostState::Pending, $states);
        self::assertCount(2, array_filter($states, static fn (BilledCostState $state): bool => BilledCostState::Billed === $state));
        foreach ($costs as $cost) {
            if (BilledCostState::Billed === $cost->state) {
                self::assertNotNull($cost->costUsd);
                self::assertNull($cost->reason);
            } else {
                self::assertNull($cost->costUsd);
                self::assertNotNull($cost->reason);
            }
        }
        $usages = self::getContainer()->get(WorkerRunUsageRepository::class)->findAll();
        self::assertCount(4, $usages);
        foreach ($usages as $usage) {
            self::assertTrue($usage->billedCost);
            self::assertNull($usage->costUsd);
        }
    }

    public function test_a_second_run_writes_no_row(): void
    {
        [$context, $seeder] = $this->seed();

        $seeder->seed($context);

        self::assertSame(4, self::getContainer()->get(WorkerRunRepository::class)->count([]));
        self::assertSame(4, self::getContainer()->get(WorkerRunBilledCostRepository::class)->count([]));
        self::assertSame(4, self::getContainer()->get(WorkerRunUsageRepository::class)->count([]));
    }

    public function test_a_deleted_billed_cost_row_comes_back(): void
    {
        [$context, $seeder] = $this->seed();
        $costs = self::getContainer()->get(WorkerRunBilledCostRepository::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->remove($costs->find(DevSeedId::of('bridge/run/openrouter/billed-1')) ?? throw new \LogicException('The row is missing.'));
        $em->flush();
        $em->clear();

        $seeder->seed($context);

        $em->clear();
        self::assertNotNull($costs->find(DevSeedId::of('bridge/run/openrouter/billed-1')));
        self::assertSame(4, $costs->count([]));
    }

    /** @return array{SeedContext, DevOpenRouterRunSeeder} */
    private function seed(): array
    {
        $context = SeedContextFactory::create(self::getContainer());
        DevProjectFixture::create(self::getContainer(), $context);
        self::getContainer()->get(DevOpenRouterProjectSeeder::class)->seed($context);
        $seeder = self::getContainer()->get(DevOpenRouterRunSeeder::class);
        $seeder->seed($context);

        return [$context, $seeder];
    }
}
