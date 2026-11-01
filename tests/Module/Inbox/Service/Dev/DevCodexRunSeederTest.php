<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeedId;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Inbox\Service\Dev\DevCodexRunSeeder;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevCodexRunSeederTest extends KernelTestCase
{
    use BridgeScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_writes_two_codex_runs_whose_facts_hold_the_harness_and_the_calls(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        $project = DevProjectFixture::create(self::getContainer(), $context);

        $this->service(DevCodexRunSeeder::class)->seed($context);

        $this->em()->clear();
        $facts = $this->service(WorkerRunFactRepository::class)->findBy(['project' => $project->id]);
        self::assertCount(2, $facts);
        foreach ($facts as $fact) {
            self::assertInstanceOf(WorkerRunFact::class, $fact);
            self::assertSame('codex', $fact->harness);
            self::assertSame('chatgpt', $fact->account);
            self::assertSame('gpt-6-sol', $fact->model);
            self::assertGreaterThan(0, $fact->costMicroUsd);
            self::assertGreaterThan(0, $fact->toolCalls);
            self::assertGreaterThan(0, $fact->subagentMs);
            self::assertNotNull($fact->modelTimeMs);
            self::assertNotNull($fact->peakContextTokens);
        }
        self::assertNotEquals($facts[0]->endedAt?->format('Y-m-d'), $facts[1]->endedAt?->format('Y-m-d'));
    }

    public function test_the_card_and_the_runs_have_fixed_ids(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        DevProjectFixture::create(self::getContainer(), $context);

        $this->service(DevCodexRunSeeder::class)->seed($context);

        $this->em()->clear();
        $card = $this->service(CardRepository::class)->find(DevSeedId::of('board/card/codex-run'));
        self::assertSame('Run a worker on Codex', $card?->title);
        foreach ([0, 1] as $index) {
            self::assertNotNull($this->service(WorkerRunRepository::class)->find(DevSeedId::of('bridge/run/codex/'.$index)));
        }
    }

    public function test_a_second_run_writes_no_row(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        $project = DevProjectFixture::create(self::getContainer(), $context);
        $seeder = $this->service(DevCodexRunSeeder::class);
        $seeder->seed($context);
        $cards = $this->service(CardRepository::class)->count(['project' => $project]);
        $runs = $this->service(WorkerRunRepository::class)->count(['project' => $project]);

        $seeder->seed($context);

        $this->em()->clear();
        self::assertSame($cards, $this->service(CardRepository::class)->count(['project' => $project]));
        self::assertSame($runs, $this->service(WorkerRunRepository::class)->count(['project' => $project]));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
