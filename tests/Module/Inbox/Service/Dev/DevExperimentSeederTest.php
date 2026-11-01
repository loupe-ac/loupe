<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeedId;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\Service\Dev\DevExperimentSeeder;
use App\Module\Project\Entity\Project;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevExperimentSeederTest extends KernelTestCase
{
    use BridgeScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_finished_card_completes_after_its_last_run_and_an_open_card_does_not(): void
    {
        $project = $this->seeded();

        $cards = $this->service(CardRepository::class)->findBy(['project' => $project->id]);
        $runsOf = $this->service(WorkerRunRepository::class);
        $finished = [];
        $open = [];
        foreach ($cards as $card) {
            if ([] === $runsOf->findBy(['subjectId' => $card->id])) {
                continue;
            }
            if ($card->column->terminal) {
                $finished[] = $card;
            } else {
                $open[] = $card;
            }
        }
        self::assertNotEmpty($finished);
        self::assertNotEmpty($open);

        $weeks = [];
        foreach ($finished as $card) {
            $ends = array_map(
                static fn (WorkerRun $run): \DateTimeImmutable => $run->endedAt ?? throw new \LogicException('A seeded run has ended.'),
                $runsOf->findBy(['subjectId' => $card->id]),
            );
            $completedAt = ([] === $ends ? self::fail($card->title.' has no run.') : max($ends))->modify('+2 hours');
            self::assertSame($completedAt->format('Y-m-d H:i:s'), $card->completedAt?->format('Y-m-d H:i:s'), $card->title);
            $weeks[$completedAt->format('o-W')] = true;
        }
        self::assertGreaterThan(1, \count($weeks));

        foreach ($open as $card) {
            self::assertNull($card->completedAt, $card->title);
        }
        foreach ($runsOf->findBy(['project' => $project->id]) as $run) {
            self::assertSame(['claude-code', 'claude'], [$run->harness, $run->account]);
        }
    }

    public function test_an_experiment_with_metrics_gives_each_merged_card_a_pull_request_that_opened_and_merged(): void
    {
        $project = $this->seeded();

        $definition = $this->service(ExperimentDefinitionRepository::class)->findOneBy(['project' => $project->id, 'experiment' => 'implement-template-metrics']);
        self::assertSame(['cost', 'merge-rate', 'hours-to-merge'], $definition?->metrics);

        $states = $this->service(ForgePullRequestRepository::class)->findBy(['project' => $project->id]);
        self::assertCount(33, $states);
        foreach ($states as $state) {
            self::assertNotNull($state->openedAt);
            self::assertNotNull($state->mergedAt);
            self::assertLessThan($state->mergedAt, $state->openedAt);
        }
    }

    public function test_the_records_a_page_names_have_fixed_ids(): void
    {
        $this->seeded();

        self::assertNotNull($this->service(ExperimentDefinitionRepository::class)->find(DevSeedId::of('bridge/experiment/implement')));
        $card = $this->service(CardRepository::class)->find(DevSeedId::of('board/card/implement/export-the-board-as-csv'));
        self::assertSame('Export the board as CSV', $card?->title);
        self::assertNotNull($this->service(WorkerRunRepository::class)->find(DevSeedId::of('bridge/run/implement/export-the-board-as-csv/0')));
    }

    public function test_a_second_run_writes_no_row(): void
    {
        $project = $this->seeded();
        $cards = $this->service(CardRepository::class)->count(['project' => $project]);
        $runs = $this->service(WorkerRunRepository::class)->count(['project' => $project]);
        $definitions = $this->service(ExperimentDefinitionRepository::class)->count(['project' => $project]);

        $this->service(DevExperimentSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));

        $this->em()->clear();
        self::assertSame($cards, $this->service(CardRepository::class)->count(['project' => $project]));
        self::assertSame($runs, $this->service(WorkerRunRepository::class)->count(['project' => $project]));
        self::assertSame($definitions, $this->service(ExperimentDefinitionRepository::class)->count(['project' => $project]));
    }

    private function seeded(): Project
    {
        $context = SeedContextFactory::create(self::getContainer());
        $project = DevProjectFixture::create(self::getContainer(), $context);
        $this->service(DevExperimentSeeder::class)->seed($context);
        $this->em()->clear();

        return $project;
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
