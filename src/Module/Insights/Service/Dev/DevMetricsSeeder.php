<?php

declare(strict_types=1);

namespace App\Module\Insights\Service\Dev;

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
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes daily runs of three models over the last 60 days, each linked to a
 * done card, and five saved graphs, so the Metrics and Dashboard pages of a
 * dev project show data and a change from the previous period. Each run fills only the days that lack a
 * demo run, so a project seeded last week gains the days since then.
 */
#[When('dev')]
#[When('test')]
final readonly class DevMetricsSeeder
{
    public const int DAYS = 60;

    /** The runs of this many days share one card. */
    public const int SPAN_DAYS = 3;

    /** Marks the demo runs. No table holds a bridge, so the id is only a label. */
    public const string BRIDGE = '01a19300-0000-7000-8000-00000000de00';

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
        private ClockInterface $clock,
    ) {
    }

    /**
     * False when every day already holds its runs and the project has saved graphs.
     *
     * @param \Closure(\DateTimeImmutable, \DateTimeImmutable): array{Uuid, int} $cardFor the id and number of the card of the runs in a span of days
     */
    public function seed(Project $project, \Closure $cardFor): bool
    {
        $runsSeeded = $this->seedRuns($project, $this->clock->now()->setTime(0, 0), $cardFor);
        $graphsSeeded = $this->seedGraphs($project);

        return $runsSeeded || $graphsSeeded;
    }

    /** @param \Closure(\DateTimeImmutable, \DateTimeImmutable): array{Uuid, int} $cardFor */
    private function seedRuns(Project $project, \DateTimeImmutable $today, \Closure $cardFor): bool
    {
        /** @var array<string, array{Uuid, int}> $cards first day of a span => its card */
        $cards = [];
        $cardOf = static function (\DateTimeImmutable $day) use (&$cards, $cardFor): array {
            $from = self::spanStart($day);

            return $cards[$from->format('Y-m-d')] ??= $cardFor($from, $from->modify(\sprintf('+%d days', self::SPAN_DAYS)));
        };

        $seeded = [];
        $stale = [];
        foreach ($this->workerRuns->findBy(['project' => $project, 'bridgeId' => Uuid::fromString(self::BRIDGE)]) as $run) {
            if (null === $run->startedAt) {
                continue;
            }
            if ($cardOf($run->startedAt)[0]->equals($run->subjectId)) {
                $seeded[$run->startedAt->format('Y-m-d')] = true;
            } else {
                $stale[] = $run->id ?? throw new \LogicException('A stored run has an id.');
            }
        }
        $this->deleteRuns($stale);

        $runs = [];
        for ($daysAgo = 1; $daysAgo <= self::DAYS; ++$daysAgo) {
            $day = $today->modify(\sprintf('-%d days', $daysAgo));
            if (isset($seeded[$day->format('Y-m-d')])) {
                continue;
            }
            /** @var array{Uuid, int} $card */
            $card = $cardOf($day);
            foreach (self::MODELS as $index => [$model, $cost, $minutes]) {
                $runs[] = $this->run($project, $card, $day, $daysAgo, $index, $model, $cost, $minutes);
            }
            if (0 === $daysAgo % 3) {
                $runs[] = $this->run($project, $card, $day, $daysAgo, 3, self::MODELS[1][0], self::MODELS[1][1], self::MODELS[1][2]);
            }
        }
        if ([] === $runs) {
            return [] !== $stale;
        }

        $this->em->flush();
        $this->bucketTimes->recompute($project, array_map(static fn (WorkerRun $run): Uuid => $run->id ?? throw new \LogicException('A stored run has an id.'), $runs));

        return true;
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
    private function run(Project $project, array $card, \DateTimeImmutable $day, int $daysAgo, int $index, string $model, float $baseCost, int $baseMinutes): WorkerRun
    {
        $wave = 1 + 0.25 * sin($daysAgo * 0.9 + $index);
        $trend = $daysAgo > 30 ? 1.15 : 1.0;
        $minutes = (int) round($baseMinutes * $wave * $trend);
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
        $this->em->persist($run);

        $costUsd = \sprintf('%.6f', $baseCost * $wave * $trend);
        $tokens = (int) round((float) $costUsd * 60_000);
        $this->em->persist(new WorkerRunUsage($run, $project, $run->subjectType, $run->subjectId, $workKind, $model, WorkerRunUsageSource::Reported, $tokens, intdiv($tokens, 15), $tokens * 5, 0, $costUsd));

        foreach (self::CALLS as $position => [$tool, $offset, $durationMs, $signatures]) {
            $kind = 'Agent' === $tool ? WorkerRunToolCallKind::Subagent : WorkerRunToolCallKind::Shell;
            $this->em->persist(new WorkerRunToolCall(Uuid::v7(), $run, $position + 1, $tool, $kind, $startedAt->modify(\sprintf('+%d seconds', $offset)), (int) round($durationMs * $wave), false, false, null, null, $signatures, null));
        }

        return $run;
    }

    private function seedGraphs(Project $project): bool
    {
        if (null !== $this->insightsSavedGraphs->findOneBy(['project' => $project])) {
            return false;
        }

        $graphs = [
            ['Cost per run by model', 'cost', MetricUnit::Run, MetricStatistic::Mean, MetricGroup::Model, MetricBucket::Day, MetricCompare::Previous, MetricChartType::Line, SavedGraphWidth::Full],
            ['Cost per run', 'cost', MetricUnit::Run, MetricStatistic::Mean, MetricGroup::None, MetricBucket::Day, MetricCompare::Previous, MetricChartType::Number, SavedGraphWidth::Half],
            ['Runs by model', 'runs', MetricUnit::Card, MetricStatistic::Count, MetricGroup::Model, MetricBucket::Week, MetricCompare::Off, MetricChartType::StackedBars, SavedGraphWidth::Half],
            ['Time in each bucket', 'bucket-time-each', MetricUnit::Run, MetricStatistic::Sum, MetricGroup::None, MetricBucket::Week, MetricCompare::Off, MetricChartType::StackedBars, SavedGraphWidth::Half],
            ['Share of runs by model', 'runs', MetricUnit::Card, MetricStatistic::Count, MetricGroup::Model, MetricBucket::Day, MetricCompare::Off, MetricChartType::Donut, SavedGraphWidth::Half],
        ];
        foreach ($graphs as $position => [$name, $metric, $unit, $statistic, $group, $bucket, $compare, $chart, $width]) {
            $this->em->persist(new InsightsSavedGraph($project, $name, $metric, $unit, $statistic, $group, MetricRange::ThirtyDays, $bucket, $compare, $chart, $width, true, $position));
        }
        $this->em->flush();

        return true;
    }
}
