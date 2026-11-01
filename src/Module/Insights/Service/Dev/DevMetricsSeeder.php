<?php

declare(strict_types=1);

namespace App\Module\Insights\Service\Dev;

use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Entity\WorkerRunToolCall;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\BucketTimeComputer;
use App\Module\Bridge\Service\Dev\DevBridgeSeeder;
use App\Module\Bridge\Service\Dev\DevRunCardLinks;
use App\Module\Bridge\Service\Dev\DevRunUsage;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunToolCallKind;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\View\MetricChartType;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes daily runs of three models over the last 60 days, each linked to a
 * done card, and five saved graphs, so the Metrics and Dashboard pages of a
 * dev project show data and a change from the previous period. Each seed writes only the runs that are
 * missing, so a project seeded last week gains the days since then, and a deleted run comes back.
 */
#[When('dev')]
#[When('test')]
final readonly class DevMetricsSeeder
{
    public const int DAYS = 60;

    /** The runs of this many days share one card. */
    public const int SPAN_DAYS = 3;

    /** The bridge row of the demo runs, which the bridge seeder writes. */
    public const string BRIDGE = DevBridgeSeeder::HISTORY_BRIDGE;

    /** @var list<array{string, float, int}> model, base cost in dollars, base duration in minutes */
    private const array MODELS = [
        ['claude-opus-5-5', 3.20, 24],
        ['claude-sonnet-5-5', 1.40, 16],
        ['claude-haiku-5-5', 0.45, 9],
    ];

    private const array WORK_KINDS = ['implement', 'review', 'fix-round'];

    /** @var list<array{string, int, int, list<string>}> tool, start offset in seconds, base duration in milliseconds, signatures */
    private const array CALLS = [
        ['Bash', 20, 30_000, ['git status']],
        ['Bash', 90, 15_000, ['grep']],
        ['Bash', 150, 180_000, ['make test']],
        ['Agent', 420, 240_000, ['Agent']],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private WorkerRunRepository $workerRuns,
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private BucketTimeComputer $bucketTimes,
        private DevRunCardLinks $runCardLinks,
        private DevRunUsage $runUsage,
    ) {
    }

    /**
     * False when every run, usage row and saved graph exists.
     *
     * @param \Closure(\DateTimeImmutable, \DateTimeImmutable): array{Uuid, int} $cardFor the id and number of the card of the runs in a span of days
     */
    public function seed(SeedContext $context, Project $project, \Closure $cardFor): bool
    {
        $runsSeeded = $this->seedRuns($context, $project, $context->now->setTime(0, 0), $cardFor);
        $graphsSeeded = $this->seedGraphs($context, $project);

        return $runsSeeded || $graphsSeeded;
    }

    /** @param \Closure(\DateTimeImmutable, \DateTimeImmutable): array{Uuid, int} $cardFor */
    private function seedRuns(SeedContext $context, Project $project, \DateTimeImmutable $today, \Closure $cardFor): bool
    {
        /** @var array<string, array{Uuid, int}> $cards first day of a span => its card */
        $cards = [];
        $cardOf = static function (\DateTimeImmutable $day) use (&$cards, $cardFor): array {
            $from = self::spanStart($day);

            return $cards[$from->format('Y-m-d')] ??= $cardFor($from, $from->modify(\sprintf('+%d days', self::SPAN_DAYS)));
        };

        /** @var array<string, WorkerRun> $stored */
        $stored = [];
        $stale = [];
        /** @var array<string, array{Uuid, int}> $restored the cards whose number differs from the number their runs hold */
        $restored = [];
        foreach ($this->workerRuns->findBy(['project' => $project, 'bridgeId' => Uuid::fromString(self::BRIDGE)]) as $run) {
            if (null === $run->startedAt) {
                continue;
            }
            $id = $run->id ?? throw new \LogicException('A stored run has an id.');
            $card = $cardOf($run->startedAt);
            if ($card[0]->equals($run->subjectId)) {
                $stored[$id->toRfc4122()] = $run;
                if ($card[1] !== $run->cardNumber) {
                    $restored[$card[0]->toRfc4122()] = $card;
                }
            } else {
                $stale[] = $id;
            }
        }
        $this->deleteRuns($stale);
        foreach ($restored as [$cardId, $number]) {
            $this->runCardLinks->relink($project, $cardId, $number);
        }

        $runs = [];
        $usageRestored = false;
        for ($daysAgo = 1; $daysAgo <= self::DAYS; ++$daysAgo) {
            $day = $today->modify(\sprintf('-%d days', $daysAgo));
            foreach (self::runsOf($day) as $index => [$model, $cost, $minutes]) {
                $run = $stored[self::runId($day, $index)->toRfc4122()] ?? null;
                if (null === $run) {
                    /** @var array{Uuid, int} $card */
                    $card = $cardOf($day);
                    $run = $this->run($context, $project, $card, $day, $daysAgo, $index, $model, $minutes);
                    $runs[] = $run;
                }
                $costUsd = \sprintf('%.6f', $cost * self::wave($daysAgo, $index) * self::trend($daysAgo));
                $tokens = (int) round((float) $costUsd * 60_000);
                $usageRestored = $this->runUsage->record($context, $run, $model, $tokens, intdiv($tokens, 15), $tokens * 5, 0, $costUsd) || $usageRestored;
            }
        }
        if ([] === $runs && !$usageRestored) {
            return [] !== $stale || [] !== $restored;
        }

        $this->em->flush();
        $this->bucketTimes->recompute($project, array_map(static fn (WorkerRun $run): Uuid => $run->id ?? throw new \LogicException('A stored run has an id.'), $runs));

        return true;
    }

    /**
     * The runs that a day holds, by index. The first day of a span holds a fourth run.
     *
     * @return array<int, array{string, float, int}>
     */
    private static function runsOf(\DateTimeImmutable $day): array
    {
        return self::spanStart($day)->format('Y-m-d') === $day->format('Y-m-d') ? [...self::MODELS, self::MODELS[1]] : self::MODELS;
    }

    private static function wave(int $daysAgo, int $index): float
    {
        return 1 + 0.25 * sin($daysAgo * 0.9 + $index);
    }

    private static function trend(int $daysAgo): float
    {
        return $daysAgo > 30 ? 1.15 : 1.0;
    }

    private static function runId(\DateTimeImmutable $day, int $index): Uuid
    {
        return DevSeedId::of(\sprintf('insights/run/%s/%d', $day->format('Y-m-d'), $index));
    }

    /** The spans start on fixed days, so a later seed finds the card of a span again. */
    private static function spanStart(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $epochDay = intdiv((int) $day->setTime(0, 0)->format('U'), 86_400);

        return $day->setTime(0, 0)->modify(\sprintf('-%d days', $epochDay % self::SPAN_DAYS));
    }

    /**
     * Removes the runs that name no stored card. The fact and usage rows hold no
     * cascading key to the run, so they go first.
     *
     * @param list<Uuid> $ids
     */
    private function deleteRuns(array $ids): void
    {
        if ([] === $ids) {
            return;
        }
        foreach ([WorkerRunFact::class => 'runId', WorkerRunUsage::class => 'run', WorkerRun::class => 'id'] as $entity => $field) {
            $this->em->createQueryBuilder()->delete($entity, 'e')->andWhere(\sprintf('e.%s IN (:ids)', $field))->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), ArrayParameterType::STRING)->getQuery()->execute();
        }
    }

    /**
     * The runs of three days share a card, so the compare sign counts enough cards in each period.
     * The previous period costs about 15% more, so a compare shows a fall. WorkerRunFactListener writes the fact row on flush.
     *
     * @param array{Uuid, int} $card
     */
    private function run(SeedContext $context, Project $project, array $card, \DateTimeImmutable $day, int $daysAgo, int $index, string $model, int $baseMinutes): WorkerRun
    {
        $wave = self::wave($daysAgo, $index);
        $minutes = (int) round($baseMinutes * $wave * self::trend($daysAgo));
        $startedAt = $day->setTime(8 + 2 * $index, 15);
        $endedAt = $startedAt->modify(\sprintf('+%d minutes', $minutes));
        $workKind = self::WORK_KINDS[($daysAgo + $index) % \count(self::WORK_KINDS)];

        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::fromString(self::BRIDGE),
            subjectType: WorkSubject::CARD,
            subjectId: $card[0],
            cardNumber: $card[1],
            workKind: $workKind,
            state: WorkerRunState::Succeeded,
            runKey: Uuid::v7(),
            startedAt: $startedAt,
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            output: 'The work is done.',
            receivedAt: $endedAt,
        );
        $run->recordHarness('claude-code', null, $model, null);
        $run->usageSource = WorkerRunUsageSource::Reported;
        $context->ids->assign($run, self::runId($day, $index));
        $this->em->persist($run);

        foreach (self::CALLS as $position => [$tool, $offset, $durationMs, $signatures]) {
            $kind = 'Agent' === $tool ? WorkerRunToolCallKind::Subagent : WorkerRunToolCallKind::Shell;
            $this->em->persist(new WorkerRunToolCall(Uuid::v7(), $run, $position + 1, $tool, $kind, $startedAt->modify(\sprintf('+%d seconds', $offset)), (int) round($durationMs * $wave), false, false, null, null, $signatures, null));
        }

        return $run;
    }

    private function seedGraphs(SeedContext $context, Project $project): bool
    {
        $graphs = [
            ['cost-per-run-by-model', 'Cost per run by model', 'cost', MetricUnit::Run, MetricStatistic::Mean, MetricGroup::Model, MetricBucket::Day, MetricCompare::Previous, MetricChartType::Line, SavedGraphWidth::Full],
            ['cost-per-run', 'Cost per run', 'cost', MetricUnit::Run, MetricStatistic::Mean, MetricGroup::None, MetricBucket::Day, MetricCompare::Previous, MetricChartType::Number, SavedGraphWidth::Half],
            ['runs-by-model', 'Runs by model', 'runs', MetricUnit::Card, MetricStatistic::Count, MetricGroup::Model, MetricBucket::Week, MetricCompare::Off, MetricChartType::StackedBars, SavedGraphWidth::Half],
            ['time-in-each-bucket', 'Time in each bucket', 'bucket-time-each', MetricUnit::Run, MetricStatistic::Sum, MetricGroup::None, MetricBucket::Week, MetricCompare::Off, MetricChartType::StackedBars, SavedGraphWidth::Half],
            ['share-of-runs-by-model', 'Share of runs by model', 'runs', MetricUnit::Card, MetricStatistic::Count, MetricGroup::Model, MetricBucket::Day, MetricCompare::Off, MetricChartType::Donut, SavedGraphWidth::Half],
        ];
        $seeded = false;
        foreach ($graphs as $position => [$slug, $name, $metric, $unit, $statistic, $group, $bucket, $compare, $chart, $width]) {
            $id = DevSeedId::of('insights/graph/'.$slug);
            if ($this->insightsSavedGraphs->find($id) instanceof InsightsSavedGraph) {
                continue;
            }
            $graph = new InsightsSavedGraph($project, $name, $metric, $unit, $statistic, $group, MetricRange::ThirtyDays, $bucket, $compare, $chart, $width, true, $position, createdAt: $context->at(\count($graphs) - $position));
            $context->ids->assign($graph, $id);
            $this->em->persist($graph);
            $seeded = true;
        }
        $this->em->flush();

        return $seeded;
    }
}
