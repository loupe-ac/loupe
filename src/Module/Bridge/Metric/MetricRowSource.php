<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\TemplateMetric;
use Symfony\Component\Uid\Uuid;

/** Turns the fact rows and the finished cards into one metric row per run or per card. */
final readonly class MetricRowSource
{
    public function __construct(
        private WorkerRunFactRepository $workerRunFacts,
        private FinishedCardSourceInterface $finishedCards,
        private CardReportSourceInterface $cardReports,
        private WorkerRunBucketTimeRepository $workerRunBucketTimes,
        private WorkerRunRepository $workerRuns,
        private TemplateMetricValues $templateValues,
    ) {
    }

    /**
     * The window runs from the start, inclusive, to the end, exclusive. A null end leaves it open.
     *
     * @return list<MetricRow>
     */
    public function rows(Project $project, MetricUnit $unit, MetricShape $shape, MetricGroup $group, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to = null): array
    {
        if (null !== $shape->template) {
            return MetricUnit::Card === $unit ? $this->templateRows($project, $shape->template, $group, $from, $to) : [];
        }
        $metric = $shape->key->metric ?? throw new \LogicException('A key has a built-in metric or a template metric.');
        if ($metric->isBucketEach()) {
            return $this->bucketEachRows($project, $unit, $metric, $from, $to);
        }

        return MetricUnit::Run === $unit
            ? $this->runRows($project, $metric, $group, $from, $to, $shape->key->name)
            : $this->cardRows($project, $metric, $group, $from, $to, $shape->key->name);
    }

    /**
     * One row per bucket name of the window for each run with bucket data, with 0 for a bucket the run did not touch,
     * so the means of the lines add up to the mean of the whole. A run with no bucket data stays out. A card row sums
     * its runs per bucket.
     *
     * @return list<MetricRow>
     */
    private function bucketEachRows(Project $project, MetricUnit $unit, Metric $metric, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        if (MetricUnit::Run === $unit) {
            $facts = $this->workerRunFacts->findClosedSince($project, $from, false, $to);
            $cards = [];
        } else {
            $cards = $this->finishedCards->finishedCards($project, $from, $to);
            $ids = array_map(static fn (FinishedCard $card): Uuid => $card->id, $cards);
            $facts = [] === $ids ? [] : $this->workerRunFacts->findOfCards($project, $ids);
        }
        if ([] === $facts) {
            return [];
        }

        $runIds = array_map(static fn (WorkerRunFact $fact): Uuid => $fact->runId, $facts);
        $figures = $this->workerRunBucketTimes->findAllSubagentFiguresOfRuns($runIds);
        $times = $this->workerRunBucketTimes->findAllMillisecondsOfRuns($runIds);
        $figureOf = static fn (string $runId, string $bucket): ?int => match ($metric) {
            Metric::BucketTimeEach => $times[$runId][$bucket] ?? 0,
            Metric::BucketTimeAllEach => isset($times[$runId][$bucket]) ? $figures[$runId][$bucket]['msWithSubagents'] ?? null : 0,
            Metric::BucketCallsEach => isset($times[$runId][$bucket]) ? $figures[$runId][$bucket]['calls'] ?? null : 0,
            default => throw new \LogicException(\sprintf('The metric %s is not a grouped bucket metric.', $metric->value)),
        };

        $names = [];
        foreach ($times as $perRun) {
            foreach (array_keys($perRun) as $name) {
                $names[(string) $name] = true;
            }
        }
        $names = array_map(strval(...), array_keys($names));
        sort($names, \SORT_STRING);

        $rows = [];
        if (MetricUnit::Run === $unit) {
            foreach ($facts as $fact) {
                $runId = (string) $fact->runId;
                if (!isset($times[$runId])) {
                    continue;
                }
                foreach ($names as $name) {
                    $rows[] = new MetricRow($fact->runId, $fact->cardNumber, $name, $fact->endedAt ?? $fact->receivedAt, $figureOf($runId, $name));
                }
            }

            return $rows;
        }

        $factsByCard = [];
        foreach ($facts as $fact) {
            $factsByCard[(string) $fact->subjectId][] = $fact;
        }
        foreach ($cards as $card) {
            $withData = array_filter($factsByCard[(string) $card->id] ?? [], static fn (WorkerRunFact $fact): bool => isset($times[(string) $fact->runId]));
            if ([] === $withData) {
                continue;
            }
            foreach ($names as $name) {
                $known = array_filter(array_map(static fn (WorkerRunFact $fact): ?int => $figureOf((string) $fact->runId, $name), $withData), static fn (?int $value): bool => null !== $value);
                $rows[] = new MetricRow($card->id, $card->number, $name, $card->completedAt, [] === $known ? null : array_sum($known));
            }
        }

        return $rows;
    }

    /**
     * Gives each run or card of grouped bucket rows a 0 row for each name it lacks. A name of the other compared
     * period is a bucket that no run of this period touched.
     *
     * @param list<MetricRow> $rows
     * @param list<string>    $names
     *
     * @return list<MetricRow>
     */
    public static function withBucketNames(array $rows, array $names): array
    {
        $held = [];
        $first = [];
        foreach ($rows as $row) {
            $held[(string) $row->unitId][(string) $row->group] = true;
            $first[(string) $row->unitId] ??= $row;
        }
        foreach ($first as $unitId => $row) {
            foreach ($names as $name) {
                if (!isset($held[$unitId][$name])) {
                    $rows[] = new MetricRow($row->unitId, $row->cardNumber, $name, $row->time, 0);
                }
            }
        }

        return $rows;
    }

    /** Reads the same runs as a run query with no stage grouping. */
    public function bucketTimes(Project $project, ?\DateTimeImmutable $from): BucketTimes
    {
        $facts = $this->workerRunFacts->findClosedSince($project, $from, false);

        $runIds = array_map(static fn (WorkerRunFact $fact): Uuid => $fact->runId, $facts);

        return new BucketTimes(
            \count($facts),
            [] === $facts ? [] : $this->workerRunBucketTimes->findAllMillisecondsOfRuns($runIds),
            [] === $facts ? [] : $this->workerRunBucketTimes->findAllSubagentFiguresOfRuns($runIds),
        );
    }

    /** @return list<MetricRow> */
    private function runRows(Project $project, Metric $metric, MetricGroup $group, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $name): array
    {
        // A stage belongs to the card workflow, so a stage grouping reads card runs alone.
        $facts = $this->workerRunFacts->findClosedSince($project, $from, MetricGroup::Stage === $group, $to);
        $types = MetricGroup::CardType === $group ? $this->cardReports->typesFor($project, self::cardIdsOf($facts)) : [];
        $times = $this->namedRunValues($facts, $metric, $name);

        return array_map(
            static fn (WorkerRunFact $fact): MetricRow => new MetricRow(
                $fact->runId,
                $fact->cardNumber,
                self::groupOf($fact, $group, $types),
                $fact->endedAt ?? $fact->receivedAt,
                null === $name ? self::runValue($fact, $metric) : $times[(string) $fact->runId] ?? null,
            ),
            $facts,
        );
    }

    /**
     * One row per finished card, with a value for every card. A variant grouping gives the whole card value to each variant its
     * runs name, as a built-in card metric does, so a card worked in two variants counts once in each.
     *
     * @return list<MetricRow>
     */
    private function templateRows(Project $project, TemplateMetric $template, MetricGroup $group, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $cards = $this->finishedCards->finishedCards($project, $from, $to);
        if ([] === $cards) {
            return [];
        }

        $ids = array_map(static fn (FinishedCard $card): Uuid => $card->id, $cards);
        $values = $this->templateValues->valuesOf($project, $ids, $template);
        $types = MetricGroup::CardType === $group ? $this->cardReports->typesFor($project, $ids) : [];
        $variants = MetricGroup::Variant === $group ? $this->variantsOf($project, $ids) : [];

        $rows = [];
        foreach ($cards as $card) {
            $id = (string) $card->id;
            $groups = MetricGroup::Variant === $group ? $variants[$id] ?? [null] : [$types[$id] ?? null];
            foreach ($groups as $key) {
                $rows[] = new MetricRow($card->id, $card->number, $key, $card->completedAt, $values[$id] ?? null);
            }
        }

        return $rows;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, list<?string>> card id => each variant its runs name, null for a run with none
     */
    private function variantsOf(Project $project, array $ids): array
    {
        $variants = [];
        foreach ($this->workerRunFacts->findOfCards($project, $ids) as $fact) {
            $variants[(string) $fact->subjectId][$fact->variant ?? ''] = $fact->variant;
        }

        return array_map(array_values(...), $variants);
    }

    /** @return list<MetricRow> */
    private function cardRows(Project $project, Metric $metric, MetricGroup $group, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $name): array
    {
        $cards = $this->finishedCards->finishedCards($project, $from, $to);
        if ([] === $cards) {
            return [];
        }

        $ids = array_map(static fn (FinishedCard $card): Uuid => $card->id, $cards);
        $factsByCard = [];
        foreach ($this->workerRunFacts->findOfCards($project, $ids) as $fact) {
            $factsByCard[(string) $fact->subjectId][] = $fact;
        }
        $times = $this->namedRunValues(array_merge(...array_values($factsByCard)), $metric, $name);
        $types = MetricGroup::CardType === $group ? $this->cardReports->typesFor($project, $ids) : [];

        $rows = [];
        foreach ($cards as $card) {
            $id = (string) $card->id;
            $facts = $factsByCard[$id] ?? [];
            /** @var array<string, array{group: ?string, facts: list<WorkerRunFact>}> $partitions */
            $partitions = [];
            if ($group->isRunGrouping() && [] !== $facts) {
                foreach ($facts as $fact) {
                    $key = self::groupOf($fact, $group, $types);
                    $partitions[null === $key ? '' : 'key:'.$key]['group'] = $key;
                    $partitions[null === $key ? '' : 'key:'.$key]['facts'][] = $fact;
                }
            } else {
                $partitions[] = ['group' => $group->isRunGrouping() ? null : $types[$id] ?? null, 'facts' => $facts];
            }

            foreach ($partitions as $partition) {
                $rows[] = new MetricRow(
                    $card->id,
                    $card->number,
                    $partition['group'],
                    $card->completedAt,
                    self::cardValue($partition['facts'], $metric, $name, $times),
                );
            }
        }

        return $rows;
    }

    /**
     * @param list<WorkerRunFact> $facts
     *
     * @return array<string, int|null> run id => value, for a named-metric query alone. A run with no entry has no value.
     */
    public function namedRunValues(array $facts, Metric $metric, ?string $name): array
    {
        if (null === $name || [] === $facts) {
            return [];
        }

        $ids = array_map(static fn (WorkerRunFact $fact): Uuid => $fact->runId, $facts);

        return match ($metric) {
            Metric::BucketTime => $this->workerRunBucketTimes->findMillisecondsOfRuns($ids, $name),
            Metric::BucketTimeAll => $this->workerRunBucketTimes->findMillisecondsWithSubagentsOfRuns($ids, $name),
            Metric::BucketCalls => $this->workerRunBucketTimes->findCallsOfRuns($ids, $name),
            Metric::HarnessEvents => $this->workerRuns->findHarnessEventCountsOfRuns($ids, $name),
            default => throw new \LogicException(\sprintf('The metric %s takes no name.', $metric->value)),
        };
    }

    /**
     * @param list<WorkerRunFact> $facts
     *
     * @return list<Uuid>
     */
    private static function cardIdsOf(array $facts): array
    {
        $ids = [];
        foreach ($facts as $fact) {
            if ('card' === $fact->subjectType) {
                $ids[(string) $fact->subjectId] = $fact->subjectId;
            }
        }

        return array_values($ids);
    }

    /** @param array<string, string> $types card id => card type */
    private static function groupOf(WorkerRunFact $fact, MetricGroup $group, array $types): ?string
    {
        return match ($group) {
            MetricGroup::Stage => $fact->workKind,
            MetricGroup::Model => $fact->model,
            MetricGroup::Variant => $fact->variant,
            MetricGroup::Bridge => $fact->bridgeId?->toRfc4122(),
            MetricGroup::Harness => $fact->harness,
            MetricGroup::Account => $fact->account,
            MetricGroup::HarnessVersion => $fact->harnessVersion,
            MetricGroup::CliVersion => $fact->cliVersion,
            MetricGroup::SkillVersion => $fact->skillVersion,
            MetricGroup::CardType => 'card' === $fact->subjectType ? $types[(string) $fact->subjectId] ?? null : null,
            MetricGroup::None => null,
        };
    }

    private static function runValue(WorkerRunFact $fact, Metric $metric): int|float|null
    {
        return self::inDollars(self::rawValue($fact, $metric), $metric);
    }

    /** Cost stays in micro-dollars here, so a sum adds integers. */
    private static function rawValue(WorkerRunFact $fact, Metric $metric): ?int
    {
        return match ($metric) {
            Metric::Cost => $fact->costMicroUsd,
            Metric::InputTokens => $fact->tokensIn,
            Metric::OutputTokens => $fact->tokensOut,
            Metric::CacheReadTokens => $fact->tokensCacheRead,
            Metric::CacheWriteTokens => $fact->tokensCacheWrite,
            Metric::Duration => $fact->durationMs,
            Metric::StopRate => $fact->outcome->isOutcome() ? (int) $fact->outcome->isStop() : null,
            Metric::Runs, Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls, Metric::BucketTimeEach, Metric::BucketTimeAllEach, Metric::BucketCallsEach, Metric::HarnessEvents => throw new \LogicException(\sprintf('The metric %s has no run value.', $metric->value)),
        };
    }

    /**
     * @param list<WorkerRunFact>     $facts
     * @param array<string, int|null> $times run id => value, for a named-metric query alone
     */
    private static function cardValue(array $facts, Metric $metric, ?string $name, array $times): int|float|null
    {
        if (Metric::Runs === $metric) {
            return \count($facts);
        }
        if (null !== $name) {
            return self::namedCardSum($facts, $times);
        }

        return self::inDollars(self::cardSum($facts, $metric), $metric);
    }

    /**
     * A named-metric sum counts the runs that have data, and a run with none
     * adds nothing, because a command run or an old run never has any.
     *
     * @param list<WorkerRunFact>     $facts
     * @param array<string, int|null> $times run id => value
     */
    public static function namedCardSum(array $facts, array $times): ?int
    {
        $known = array_filter(array_map(static fn (WorkerRunFact $fact): ?int => $times[(string) $fact->runId] ?? null, $facts), static fn (?int $value): bool => null !== $value);

        return [] === $known ? null : array_sum($known);
    }

    /**
     * The sum over the runs of a card, with cost in micro-dollars. A run that
     * started with no value makes the sum unknown, and so does a run with usage
     * and no cost or tokens. A command run has no agent, so its missing cost and
     * tokens count as none. Null when no run gives a value.
     *
     * @param list<WorkerRunFact> $facts
     */
    public static function cardSum(array $facts, Metric $metric): ?int
    {
        if (!$metric->isAdditive() || Metric::Runs === $metric) {
            throw new \LogicException(\sprintf('The metric %s has no sum over runs.', $metric->value));
        }

        $sum = null;
        foreach ($facts as $fact) {
            $value = self::rawValue($fact, $metric);
            if (null === $value) {
                $counts = Metric::Duration === $metric
                    ? null !== $fact->startedAt
                    : WorkerRunKind::Command !== $fact->kind && (null !== $fact->startedAt || null !== $fact->usageSource);
                if ($counts) {
                    return null;
                }
                continue;
            }
            $sum = ($sum ?? 0) + $value;
        }

        return $sum;
    }

    private static function inDollars(?int $value, Metric $metric): int|float|null
    {
        return Metric::Cost === $metric && null !== $value ? $value / 1_000_000.0 : $value;
    }
}
