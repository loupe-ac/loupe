<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeedId;
use App\Module\Board\Entity\Card;
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

    public function test_a_deleted_card_comes_back_and_its_runs_take_its_new_number(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        $project = DevProjectFixture::create(self::getContainer(), $context);
        $seeder = $this->service(DevCodexRunSeeder::class);
        $seeder->seed($context);
        $cards = $this->service(CardRepository::class);
        $card = $cards->find(DevSeedId::of('board/card/codex-run')) ?? throw new \LogicException('The card is missing.');
        $oldNumber = $card->number;
        $this->em()->persist(new Card(project: $project, column: $card->column, title: 'Later', body: '', number: $oldNumber + 1, type: 'feature'));
        $this->em()->remove($card);
        $this->em()->flush();
        $this->em()->clear();

        $seeder->seed($context);

        $this->em()->clear();
        $restored = $cards->find(DevSeedId::of('board/card/codex-run')) ?? throw new \LogicException('The card did not come back.');
        self::assertNotSame($oldNumber, $restored->number);
        self::assertSame(2, $this->service(WorkerRunRepository::class)->count(['project' => $project]));
        foreach ([0, 1] as $index) {
            self::assertSame($restored->number, $this->service(WorkerRunRepository::class)->find(DevSeedId::of('bridge/run/codex/'.$index))?->cardNumber);
        }
        foreach ($this->service(WorkerRunFactRepository::class)->findBy(['project' => $project->id]) as $fact) {
            self::assertSame($restored->number, $fact->cardNumber);
        }
    }

    public function test_a_run_that_is_gone_comes_back_and_the_card_stays(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        $project = DevProjectFixture::create(self::getContainer(), $context);
        $seeder = $this->service(DevCodexRunSeeder::class);
        $seeder->seed($context);
        $cards = $this->service(CardRepository::class)->count(['project' => $project]);
        $this->em()->remove($this->service(WorkerRunRepository::class)->find(DevSeedId::of('bridge/run/codex/0')) ?? throw new \LogicException('The run is missing.'));
        $this->em()->flush();
        $this->em()->clear();

        $seeder->seed($context);

        $this->em()->clear();
        self::assertSame($cards, $this->service(CardRepository::class)->count(['project' => $project]));
        self::assertNotNull($this->service(WorkerRunRepository::class)->find(DevSeedId::of('bridge/run/codex/0')));
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
