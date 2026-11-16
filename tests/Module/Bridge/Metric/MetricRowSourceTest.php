<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricFacts;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Metric\TemplateMetricValues;
use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class MetricRowSourceTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_bucket_times_count_the_closed_runs_in_range_and_hold_the_runs_with_data(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'row-source-buckets-'.uniqid().'@example.com'), 'Row source buckets');
        $other = $this->project($em, $this->user($em, 'row-source-other-'.uniqid().'@example.com'), 'Row source other');
        $withData = $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-09-03 10:00:00'));
        $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-09-02 10:00:00'));
        $analysis = $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-09-02 11:00:00'), subjectType: 'analysis');
        $old = $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-07-01 10:00:00'));
        $open = $this->seedRun($em, $project, state: WorkerRunState::Running, endedAt: new \DateTimeImmutable('2026-09-03 10:00:00'));
        $elsewhere = $this->seedRun($em, $other, endedAt: new \DateTimeImmutable('2026-09-03 10:00:00'));
        $bucketTimes = $this->bucketTimeRepository();
        $bucketTimes->replaceForRun($withData->id ?? throw new \LogicException(), self::figures(['tests' => 40, 'git' => 2]));
        $bucketTimes->replaceForRun($analysis->id ?? throw new \LogicException(), self::figures(['git' => 5]));
        $bucketTimes->replaceForRun($old->id ?? throw new \LogicException(), self::figures(['git' => 7]));
        $bucketTimes->replaceForRun($open->id ?? throw new \LogicException(), self::figures(['git' => 8]));
        $bucketTimes->replaceForRun($elsewhere->id ?? throw new \LogicException(), self::figures(['git' => 9]));

        $times = $this->source()->bucketTimes(new MetricFacts($project, new \DateTimeImmutable('2026-12-01')), new \DateTimeImmutable('2026-09-01 00:00:00'));

        self::assertSame(3, $times->runs);
        $read = $times->times;
        ksort($read);
        foreach ($read as &$buckets) {
            ksort($buckets);
        }
        unset($buckets);
        $expected = [(string) $withData->id => ['git' => 2, 'tests' => 40], (string) $analysis->id => ['git' => 5]];
        ksort($expected);
        self::assertSame($expected, $read);

        $all = $this->source()->bucketTimes(new MetricFacts($project, new \DateTimeImmutable('2026-12-01')), null);
        self::assertSame(4, $all->runs);
        self::assertArrayHasKey((string) $old->id, $all->times);
    }

    public function test_bucket_times_of_a_project_with_no_runs_are_empty(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'row-source-empty-'.uniqid().'@example.com'), 'Row source empty');

        $times = $this->source()->bucketTimes(new MetricFacts($project, new \DateTimeImmutable('2026-12-01')), null);

        self::assertSame(0, $times->runs);
        self::assertSame([], $times->times);
    }

    public function test_a_shared_fact_set_gives_the_rows_of_separate_sets(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'row-source-shared-'.uniqid().'@example.com'), 'Row source shared');
        $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-10-20 10:00:00'));
        $this->seedRun($em, $project, workKind: 'review', endedAt: new \DateTimeImmutable('2026-10-05 10:00:00'));
        $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-09-10 10:00:00'), subjectType: 'analysis');
        $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-08-01 10:00:00'));
        $this->seedRun($em, $project, state: WorkerRunState::Running, endedAt: new \DateTimeImmutable('2026-10-21 10:00:00'));
        $stamp = new \DateTimeImmutable('2026-11-01 00:00:00');
        $shape = new MetricShape(new MetricKey(Metric::Cost));
        $source = $this->source();

        $shared = new MetricFacts($project, $stamp);
        $source->load($shared, true, new \DateTimeImmutable('2026-08-01 00:00:00'), false, null);

        $windows = [
            [new \DateTimeImmutable('2026-10-02 00:00:00'), $stamp],
            [new \DateTimeImmutable('2026-09-02 00:00:00'), new \DateTimeImmutable('2026-10-02 00:00:00')],
            [new \DateTimeImmutable('2026-08-01 00:00:00'), $stamp],
        ];
        foreach ([MetricGroup::None, MetricGroup::Stage] as $group) {
            foreach ($windows as [$from, $to]) {
                $alone = $source->rows(new MetricFacts($project, $stamp), MetricUnit::Run, $shape, $group, $from, $to);
                self::assertTrue(MetricGroup::Stage === $group || [] !== $alone);
                self::assertEquals($alone, $source->rows($shared, MetricUnit::Run, $shape, $group, $from, $to));
            }
        }
        self::assertSame(4, \count($shared->closedRuns ?? []));
    }

    public function test_a_shared_fact_set_gives_the_card_rows_the_all_range_and_the_formula_rows_of_separate_sets(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'row-source-shared-cards-'.uniqid().'@example.com'), 'Row source shared cards');
        $recent = Uuid::v7();
        $earlier = Uuid::v7();
        $this->seedRun($em, $project, cardId: $recent, endedAt: new \DateTimeImmutable('2026-10-20 10:00:00'));
        $this->seedRun($em, $project, workKind: 'review', cardId: $recent, endedAt: new \DateTimeImmutable('2026-10-21 10:00:00'));
        $this->seedRun($em, $project, cardId: $earlier, endedAt: new \DateTimeImmutable('2026-09-20 10:00:00'));
        $cards = [
            new FinishedCard($earlier, 1, 'Earlier', new \DateTimeImmutable('2026-09-21 00:00:00')),
            new FinishedCard($recent, 2, 'Recent', new \DateTimeImmutable('2026-10-22 00:00:00')),
        ];
        $finished = new readonly class($cards) implements FinishedCardSourceInterface {
            /** @param list<FinishedCard> $cards */
            public function __construct(
                private array $cards,
            ) {
            }

            public function finishedCards(Project $project, ?\DateTimeImmutable $completedSince, ?\DateTimeImmutable $completedBefore = null): array
            {
                return array_values(array_filter($this->cards, static fn (FinishedCard $card): bool => (null === $completedSince || $card->completedAt >= $completedSince) && (null === $completedBefore || $card->completedAt < $completedBefore)));
            }
        };
        $stamp = new \DateTimeImmutable('2026-11-01 00:00:00');
        $source = $this->source($finished);
        $shapes = [new MetricShape(new MetricKey(Metric::Cost)), new MetricShape(new MetricKey(Metric::Duration))];

        $shared = new MetricFacts($project, $stamp);
        $source->load($shared, true, null, true, null);

        $windows = [
            [new \DateTimeImmutable('2026-10-02 00:00:00'), $stamp],
            [new \DateTimeImmutable('2026-09-02 00:00:00'), new \DateTimeImmutable('2026-10-02 00:00:00')],
            [null, $stamp],
        ];
        foreach ([MetricGroup::None, MetricGroup::Stage] as $group) {
            foreach ($windows as [$from, $to]) {
                foreach ([MetricUnit::Card, MetricUnit::Run] as $unit) {
                    $alone = $source->rowsForMany(new MetricFacts($project, $stamp), $unit, $shapes, $group, $from, $to);
                    self::assertNotSame([[], []], $alone);
                    self::assertEquals($alone, $source->rowsForMany($shared, $unit, $shapes, $group, $from, $to));
                }
            }
        }
    }

    public function test_a_shared_fact_set_gives_the_bucket_rows_and_the_bucket_totals_of_separate_sets_in_both_windows_of_a_compare(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'row-source-shared-buckets-'.uniqid().'@example.com'), 'Row source shared buckets');
        $recent = Uuid::v7();
        $earlier = Uuid::v7();
        $bucketTimes = $this->bucketTimeRepository();
        $runs = [
            $this->seedRun($em, $project, cardId: $recent, endedAt: new \DateTimeImmutable('2026-10-20 10:00:00')),
            $this->seedRun($em, $project, workKind: 'review', cardId: $recent, endedAt: new \DateTimeImmutable('2026-10-21 10:00:00')),
            $this->seedRun($em, $project, cardId: $earlier, endedAt: new \DateTimeImmutable('2026-09-20 10:00:00')),
            $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-09-25 10:00:00')),
        ];
        $bucketTimes->replaceForRun($runs[0]->id ?? throw new \LogicException(), self::figures(['tests' => 40, 'git' => 2]));
        $bucketTimes->replaceForRun($runs[1]->id ?? throw new \LogicException(), self::figures(['git' => 5]));
        $bucketTimes->replaceForRun($runs[2]->id ?? throw new \LogicException(), self::figures(['tests' => 11]));
        $cards = [
            new FinishedCard($earlier, 1, 'Earlier', new \DateTimeImmutable('2026-09-21 00:00:00')),
            new FinishedCard($recent, 2, 'Recent', new \DateTimeImmutable('2026-10-22 00:00:00')),
        ];
        $finished = new readonly class($cards) implements FinishedCardSourceInterface {
            /** @param list<FinishedCard> $cards */
            public function __construct(
                private array $cards,
            ) {
            }

            public function finishedCards(Project $project, ?\DateTimeImmutable $completedSince, ?\DateTimeImmutable $completedBefore = null): array
            {
                return array_values(array_filter($this->cards, static fn (FinishedCard $card): bool => (null === $completedSince || $card->completedAt >= $completedSince) && (null === $completedBefore || $card->completedAt < $completedBefore)));
            }
        };
        $stamp = new \DateTimeImmutable('2026-11-01 00:00:00');
        $source = $this->source($finished);
        $shared = new MetricFacts($project, $stamp);
        $source->load($shared, true, new \DateTimeImmutable('2026-09-01 00:00:00'), true, new \DateTimeImmutable('2026-09-01 00:00:00'));
        $windows = [
            [new \DateTimeImmutable('2026-10-01 00:00:00'), $stamp],
            [new \DateTimeImmutable('2026-09-01 00:00:00'), new \DateTimeImmutable('2026-10-01 00:00:00')],
        ];

        foreach ([Metric::BucketTimeEach, Metric::BucketTimeAllEach, Metric::BucketCallsEach] as $metric) {
            foreach ([MetricUnit::Run, MetricUnit::Card] as $unit) {
                foreach ($windows as [$from, $to]) {
                    $shape = new MetricShape(new MetricKey($metric));
                    $alone = $source->rows(new MetricFacts($project, $stamp), $unit, $shape, MetricGroup::None, $from, $to);
                    self::assertNotSame([], $alone);
                    self::assertEquals($alone, $source->rows($shared, $unit, $shape, MetricGroup::None, $from, $to));
                }
            }
        }
        foreach ($windows as [$from]) {
            $alone = $source->bucketTimes(new MetricFacts($project, $stamp), $from);
            $together = $source->bucketTimes($shared, $from);
            self::assertGreaterThan(0, $alone->runs);
            self::assertEquals($alone, $together);
        }
    }

    public function test_a_window_before_the_loaded_start_loads_only_the_missing_part(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'row-source-missing-'.uniqid().'@example.com'), 'Row source missing');
        $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-10-20 10:00:00'));
        $this->seedRun($em, $project, endedAt: new \DateTimeImmutable('2026-07-01 10:00:00'));
        $stamp = new \DateTimeImmutable('2026-11-01 00:00:00');
        $source = $this->source();
        $facts = new MetricFacts($project, $stamp);
        $source->load($facts, true, new \DateTimeImmutable('2026-10-01 00:00:00'), false, null);
        self::assertCount(1, $facts->closedRuns ?? []);

        $rows = $source->rows($facts, MetricUnit::Run, new MetricShape(new MetricKey(Metric::Cost)), MetricGroup::None, new \DateTimeImmutable('2026-06-01 00:00:00'), $stamp);

        self::assertCount(2, $rows);
        self::assertEquals(new \DateTimeImmutable('2026-06-01 00:00:00'), $facts->runsFrom);
        self::assertCount(2, $facts->closedRuns ?? []);
    }

    public function test_a_window_that_ends_after_the_stamp_is_refused(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'row-source-late-'.uniqid().'@example.com'), 'Row source late');

        $this->expectException(\LogicException::class);

        $this->source()->rows(new MetricFacts($project, new \DateTimeImmutable('2026-11-01 00:00:00')), MetricUnit::Run, new MetricShape(new MetricKey(Metric::Cost)), MetricGroup::None, null, new \DateTimeImmutable('2026-11-02 00:00:00'));
    }

    public function test_the_finished_cards_and_their_runs_load_once_for_every_window(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'row-source-cards-'.uniqid().'@example.com'), 'Row source cards');
        $recent = Uuid::v7();
        $earlier = Uuid::v7();
        $this->seedRun($em, $project, cardId: $recent, endedAt: new \DateTimeImmutable('2026-10-20 10:00:00'));
        $this->seedRun($em, $project, cardId: $earlier, endedAt: new \DateTimeImmutable('2026-09-20 10:00:00'));
        $this->seedRun($em, $project, cardId: $earlier, state: WorkerRunState::Running, endedAt: new \DateTimeImmutable('2026-11-01 10:00:00'));
        $cards = [
            new FinishedCard($earlier, 1, 'Earlier', new \DateTimeImmutable('2026-09-21 00:00:00')),
            new FinishedCard($recent, 2, 'Recent', new \DateTimeImmutable('2026-10-21 00:00:00')),
        ];
        $finished = new class($cards) implements FinishedCardSourceInterface {
            /** @var list<array{?\DateTimeImmutable, ?\DateTimeImmutable}> */
            public array $reads = [];

            /** @param list<FinishedCard> $cards */
            public function __construct(
                private readonly array $cards,
            ) {
            }

            public function finishedCards(Project $project, ?\DateTimeImmutable $completedSince, ?\DateTimeImmutable $completedBefore = null): array
            {
                $this->reads[] = [$completedSince, $completedBefore];

                return array_values(array_filter($this->cards, static fn (FinishedCard $card): bool => (null === $completedSince || $card->completedAt >= $completedSince) && (null === $completedBefore || $card->completedAt < $completedBefore)));
            }
        };
        $stamp = new \DateTimeImmutable('2026-11-02 00:00:00');
        $source = $this->source($finished);
        $shape = new MetricShape(new MetricKey(Metric::Runs));
        $facts = new MetricFacts($project, $stamp);
        $source->load($facts, false, null, true, new \DateTimeImmutable('2026-09-01 00:00:00'));

        $current = $source->rows($facts, MetricUnit::Card, $shape, MetricGroup::None, new \DateTimeImmutable('2026-10-01 00:00:00'), $stamp);
        $previous = $source->rows($facts, MetricUnit::Card, $shape, MetricGroup::None, new \DateTimeImmutable('2026-09-01 00:00:00'), new \DateTimeImmutable('2026-10-01 00:00:00'));

        self::assertCount(1, $finished->reads);
        self::assertSame([1], array_map(static fn (MetricRow $row): int|float|null => $row->value, $current));
        self::assertSame([2], array_map(static fn (MetricRow $row): int|float|null => $row->value, $previous));

        $source->rows($facts, MetricUnit::Card, $shape, MetricGroup::None, new \DateTimeImmutable('2026-08-01 00:00:00'), $stamp);
        self::assertCount(2, $finished->reads);
        self::assertEquals(new \DateTimeImmutable('2026-09-01 00:00:00'), $finished->reads[1][1]);
    }

    private function source(?FinishedCardSourceInterface $finished = null): MetricRowSource
    {
        $facts = self::getContainer()->get(WorkerRunFactRepository::class);
        self::assertInstanceOf(WorkerRunFactRepository::class, $facts);

        $workRequests = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $workRequests);
        $reports = $this->createStub(CardReportSourceInterface::class);
        $bridges = self::getContainer()->get(BridgeRepository::class);
        self::assertInstanceOf(BridgeRepository::class, $bridges);
        $readings = self::getContainer()->get(BridgeAccountReadingRepository::class);
        self::assertInstanceOf(BridgeAccountReadingRepository::class, $readings);

        return new MetricRowSource(
            $facts,
            $finished ?? $this->createStub(FinishedCardSourceInterface::class),
            $reports,
            $this->bucketTimeRepository(),
            $this->workerRunRepository(),
            new TemplateMetricValues($workRequests, $reports, $this->createStub(TemplateMetrics::class), []),
            $bridges,
            $readings,
        );
    }

    private function workerRunRepository(): WorkerRunRepository
    {
        $repository = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $repository);

        return $repository;
    }

    private function bucketTimeRepository(): WorkerRunBucketTimeRepository
    {
        $repository = self::getContainer()->get(WorkerRunBucketTimeRepository::class);
        self::assertInstanceOf(WorkerRunBucketTimeRepository::class, $repository);

        return $repository;
    }
}
