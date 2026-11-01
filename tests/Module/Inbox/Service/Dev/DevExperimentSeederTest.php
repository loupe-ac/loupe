<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeedId;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\Service\Dev\DevExperimentSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
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

    public function test_a_deleted_card_comes_back_with_its_merge_event_and_pull_request_and_its_rows_take_its_new_number(): void
    {
        $project = $this->seeded();
        $cards = $this->service(CardRepository::class);
        $id = DevSeedId::of('board/card/implement-template-metrics/export-the-board-as-csv');
        $card = $cards->find($id) ?? throw new \LogicException('The card is missing.');
        $oldNumber = $card->number;
        $runs = $this->service(WorkerRunRepository::class)->count(['project' => $project]);
        $this->service(DeleteCardHandler::class)(new DeleteCardCommand($card, Actor::Human));
        $this->em()->clear();

        $this->service(DevExperimentSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));

        $this->em()->clear();
        $restored = $cards->find($id) ?? throw new \LogicException('The card did not come back.');
        self::assertNotSame($oldNumber, $restored->number);
        self::assertSame($runs, $this->service(WorkerRunRepository::class)->count(['project' => $project]));
        $run = $this->service(WorkerRunRepository::class)->find(DevSeedId::of('bridge/run/implement-template-metrics/export-the-board-as-csv/0'));
        self::assertSame($restored->number, $run?->cardNumber);
        self::assertNotNull($restored->completedAt);
        self::assertNotNull($this->service(ForgePullRequestRepository::class)->findOneBy(['project' => $project->id, 'number' => $restored->number])?->mergedAt);
        self::assertSame(1, $this->service(CardEventRepository::class)->count(['card' => $restored->id, 'kind' => CardEventKind::Moved]));
    }

    public function test_a_deleted_definition_comes_back_and_writes_no_second_pin_run_or_fix_request(): void
    {
        $project = $this->seeded();
        $counts = $this->counts($project);
        $id = DevSeedId::of('bridge/experiment/implement');
        $this->em()->remove($this->em()->find(ExperimentDefinition::class, $id) ?? throw new \LogicException('The definition is missing.'));
        $this->em()->flush();
        $this->em()->clear();

        $this->service(DevExperimentSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));

        $this->em()->clear();
        self::assertNotNull($this->service(ExperimentDefinitionRepository::class)->find($id));
        self::assertSame($counts, $this->counts($project));
    }

    public function test_a_deleted_pin_run_or_fix_request_comes_back_and_nothing_else_doubles(): void
    {
        $project = $this->seeded();
        $counts = $this->counts($project);
        $em = $this->em();
        $cardId = DevSeedId::of('board/card/implement/show-the-run-cost-on-a-card');
        $runId = DevSeedId::of('bridge/run/implement/show-the-run-cost-on-a-card/0');
        $requestId = DevSeedId::of('bridge/work-request/implement/show-the-run-cost-on-a-card/conflict');
        $pins = $this->service(ExperimentPinRepository::class);
        $em->remove($pins->findOneBy(['project' => $project->id, 'cardId' => $cardId, 'experiment' => 'implement']) ?? throw new \LogicException('The pin is missing.'));
        $em->remove($em->find(WorkerRun::class, $runId) ?? throw new \LogicException('The run is missing.'));
        $em->remove($em->find(WorkRequest::class, $requestId) ?? throw new \LogicException('The fix request is missing.'));
        $em->flush();
        $em->clear();
        self::assertSame($counts['pins'] - 1, $this->counts($project)['pins']);

        $this->service(DevExperimentSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));

        $em->clear();
        self::assertSame($counts, $this->counts($project));
        self::assertNotNull($pins->findOneBy(['project' => $project->id, 'cardId' => $cardId, 'experiment' => 'implement']));
        self::assertNotNull($em->find(WorkerRun::class, $runId));
        self::assertNotNull($em->find(WorkRequest::class, $requestId));
    }

    public function test_a_card_deleted_with_its_definition_comes_back_and_its_stored_runs_take_its_new_number(): void
    {
        $project = $this->seeded();
        $counts = $this->counts($project);
        $cards = $this->service(CardRepository::class);
        $id = DevSeedId::of('board/card/implement/fix-a-stale-badge-count');
        $card = $cards->find($id) ?? throw new \LogicException('The card is missing.');
        $oldNumber = $card->number;
        $this->service(DeleteCardHandler::class)(new DeleteCardCommand($card, Actor::Human));
        $this->em()->remove($this->em()->find(ExperimentDefinition::class, DevSeedId::of('bridge/experiment/implement')) ?? throw new \LogicException('The definition is missing.'));
        $this->em()->flush();
        $this->em()->clear();

        $this->service(DevExperimentSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));

        $this->em()->clear();
        $restored = $cards->find($id) ?? throw new \LogicException('The card did not come back.');
        self::assertNotSame($oldNumber, $restored->number);
        self::assertSame($counts, $this->counts($project));
        foreach (['bridge/run/implement/fix-a-stale-badge-count/0', 'bridge/run/implement/fix-a-stale-badge-count/1'] as $key) {
            self::assertSame($restored->number, $this->service(WorkerRunRepository::class)->find(DevSeedId::of($key))?->cardNumber, $key);
        }
        self::assertSame($restored->number, $this->service(WorkRequestRepository::class)->find(DevSeedId::of('bridge/work-request/implement/fix-a-stale-badge-count/checks-failed'))?->cardNumber);
    }

    /** @return array{pins: int, runs: int, requests: int, cards: int} */
    private function counts(Project $project): array
    {
        return [
            'pins' => $this->service(ExperimentPinRepository::class)->count(['project' => $project->id]),
            'runs' => $this->service(WorkerRunRepository::class)->count(['project' => $project->id]),
            'requests' => $this->service(WorkRequestRepository::class)->count(['project' => $project->id]),
            'cards' => $this->service(CardRepository::class)->count(['project' => $project->id]),
        ];
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
