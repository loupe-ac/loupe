<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Command\MetricQueryCommand;
use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\AccountUsageReading;
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
        private BridgeRepository $bridges,
        private BridgeAccountReadingRepository $bridgeAccountReadings,
    ) {
    }

    /**
     * The window runs from the start, inclusive, to the end, exclusive. A null end leaves it open.
     *
     * @return list<MetricRow>
     */
    public function rows(MetricFacts $facts, MetricUnit $unit, MetricShape $shape, MetricGroup $group, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to = null): array
    {
        if (null !== $shape->template) {
            return MetricUnit::Card === $unit ? $this->templateRows($facts, $shape->template, $group, $from, $to) : [];
        }
        $metric = $shape->key->metric ?? throw new \LogicException('A key has a built-in metric or a template metric.');
        if ($metric->isBucketEach()) {
            return $this->bucketEachRows($facts, $unit, $metric, $from, $to);
        }
        if (MetricUnit::Reading === $unit) {
            return Metric::AccountUsage === $metric ? $this->readingRows($facts->project, $from, $to) : [];
        }

        return $this->rowsForMany($facts, $unit, [$shape], $group, $from, $to)[0];
    }

    /**
     * The rows of several built-in metrics over one window, with the facts or the cards loaded once. The answer holds one
     * list for each shape, in the order of the shapes. Only the run and the card unit read this way.
     *
     * @param list<MetricShape> $shapes
     *
     * @return list<list<MetricRow>>
     */
    public function rowsForMany(MetricFacts $facts, MetricUnit $unit, array $shapes, MetricGroup $group, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to = null): array
    {
        foreach ($shapes as $shape) {
            if (null !== $shape->template || true === $shape->key->metric?->isBucketEach()) {
                throw new \LogicException('Only a built-in metric with a value per run reads in a batch.');
            }
        }

        return match ($unit) {
            MetricUnit::Run => $this->runRowsMany($facts, $shapes, $group, $from, $to),
            MetricUnit::Card => $this->cardRowsMany($facts, $shapes, $group, $from, $to),
            MetricUnit::Reading => throw new \LogicException('A reading is not read in a batch.'),
        };
    }

    /**
     * One row per bucket name of the window for each run with bucket data, with 0 for a bucket the run did not touch,
     * so the means of the lines add up to the mean of the whole. A run with no bucket data stays out. A card row sums
     * its runs per bucket.
     *
     * @return list<MetricRow>
     */
    private function bucketEachRows(MetricFacts $facts, MetricUnit $unit, Metric $metric, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        if (MetricUnit::Run === $unit) {
            $runs = $this->closedRuns($facts, $from, $to, false);
            $cards = [];
        } else {
            $cards = $this->finishedCardsOf($facts, $from, $to);
            $ids = array_map(static fn (FinishedCard $card): Uuid => $card->id, $cards);
            $runs = array_merge(...array_values($this->runsOfCards($facts, $ids)));
        }
        if ([] === $runs) {
            return [];
        }

        $runIds = array_map(static fn (WorkerRunFact $fact): Uuid => $fact->runId, $runs);
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
            foreach ($runs as $fact) {
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
        foreach ($runs as $fact) {
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

    /**
     * One row per stored reading that has a use value, of the named bridges of the owner that follow the project. A
     * bridge with no name has no label for its group, so its readings stay out.
     *
     * @return list<MetricRow>
     */
    private function readingRows(Project $project, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $names = [];
        foreach ($this->bridges->findFollowingProject($project) as $bridge) {
            if (null !== $bridge->name) {
                $names[$bridge->id->toRfc4122()] = $bridge->name;
            }
        }
        if ([] === $names) {
            return [];
        }

        $ownerId = $project->owner->id ?? throw new \LogicException('A persisted owner has an id.');
        $readings = $this->bridgeAccountReadings->findUsageOfBridges($ownerId, array_map(Uuid::fromString(...), array_keys($names)), $from, $to);

        return array_map(
            static fn (AccountUsageReading $reading): MetricRow => new MetricRow(
                $reading->id,
                null,
                \sprintf('%s/%s %s', $names[$reading->bridgeId->toRfc4122()], $reading->account, $reading->window),
                $reading->readAt,
                $reading->usedPercent,
                new MetricGroupKey($reading->bridgeId->toRfc4122(), $reading->account, $reading->window),
            ),
            $readings,
        );
    }

    /** Reads the same runs as a run query with no stage grouping. */
    public function bucketTimes(MetricFacts $facts, ?\DateTimeImmutable $from): BucketTimes
    {
        $runs = $this->closedRuns($facts, $from, null, false);

        $runIds = array_map(static fn (WorkerRunFact $fact): Uuid => $fact->runId, $runs);

        return new BucketTimes(
            \count($runs),
            [] === $runs ? [] : $this->workerRunBucketTimes->findAllMillisecondsOfRuns($runIds),
            [] === $runs ? [] : $this->workerRunBucketTimes->findAllSubagentFiguresOfRuns($runIds),
        );
    }

    /**
     * @param list<MetricShape> $shapes
     *
     * @return list<list<MetricRow>>
     */
    private function runRowsMany(MetricFacts $facts, array $shapes, MetricGroup $group, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        // A stage belongs to the card workflow, so a stage grouping reads card runs alone.
        $runs = $this->closedRuns($facts, $from, $to, MetricGroup::Stage === $group);
        $types = MetricGroup::CardType === $group ? $this->cardReports->typesFor($facts->project, self::cardIdsOf($runs)) : [];

        $result = [];
        foreach ($shapes as $shape) {
            $metric = $shape->key->metric ?? throw new \LogicException('A key has a built-in metric or a template metric.');
            $name = $shape->key->name;
            $times = $this->namedRunValues($runs, $metric, $name);
            $result[] = array_map(
                static fn (WorkerRunFact $fact): MetricRow => new MetricRow(
                    $fact->runId,
                    $fact->cardNumber,
                    self::groupOf($fact, $group, $types),
                    $fact->endedAt ?? $fact->receivedAt,
                    null === $name ? self::runValue($fact, $metric) : $times[(string) $fact->runId] ?? null,
                    self::keyOf($group, self::groupOf($fact, $group, $types)),
                ),
                $runs,
            );
        }

        return $result;
    }

    /**
     * One row per finished card, with a value for every card. A variant grouping gives the whole card value to each variant its
     * runs name, as a built-in card metric does, so a card worked in two variants counts once in each.
     *
     * @return list<MetricRow>
     */
    private function templateRows(MetricFacts $facts, TemplateMetric $template, MetricGroup $group, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $cards = $this->finishedCardsOf($facts, $from, $to);
        if ([] === $cards) {
            return [];
        }

        $ids = array_map(static fn (FinishedCard $card): Uuid => $card->id, $cards);
        $values = $this->templateValues->valuesOf($facts->project, $ids, $template);
        $types = MetricGroup::CardType === $group ? $this->cardReports->typesFor($facts->project, $ids) : [];
        $variants = MetricGroup::Variant === $group ? $this->variantsOf($facts, $ids) : [];

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
    private function variantsOf(MetricFacts $facts, array $ids): array
    {
        $variants = [];
        foreach ($this->runsOfCards($facts, $ids) as $runs) {
            foreach ($runs as $fact) {
                $variants[(string) $fact->subjectId][$fact->variant ?? ''] = $fact->variant;
            }
        }

        return array_map(array_values(...), $variants);
    }

    /**
     * @param list<MetricShape> $shapes
     *
     * @return list<list<MetricRow>>
     */
    private function cardRowsMany(MetricFacts $facts, array $shapes, MetricGroup $group, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $cards = $this->finishedCardsOf($facts, $from, $to);
        if ([] === $cards) {
            return array_map(static fn (MetricShape $shape): array => [], $shapes);
        }

        $ids = array_map(static fn (FinishedCard $card): Uuid => $card->id, $cards);
        $factsByCard = $this->runsOfCards($facts, $ids);
        $allFacts = array_merge(...array_values($factsByCard));
        $types = MetricGroup::CardType === $group ? $this->cardReports->typesFor($facts->project, $ids) : [];

        // The partitions of a card depend on the grouping alone, so every metric shares them.
        $partitionsOf = [];
        foreach ($cards as $card) {
            $id = (string) $card->id;
            $cardRuns = $factsByCard[$id] ?? [];
            /** @var array<string, array{group: ?string, facts: list<WorkerRunFact>}> $partitions */
            $partitions = [];
            if ($group->isRunGrouping() && [] !== $cardRuns) {
                foreach ($cardRuns as $fact) {
                    $key = self::groupOf($fact, $group, $types);
                    $partitions[null === $key ? '' : 'key:'.$key]['group'] = $key;
                    $partitions[null === $key ? '' : 'key:'.$key]['facts'][] = $fact;
                }
            } else {
                $partitions[] = ['group' => $group->isRunGrouping() ? null : $types[$id] ?? null, 'facts' => $cardRuns];
            }
            $partitionsOf[$id] = $partitions;
        }

        $result = [];
        foreach ($shapes as $shape) {
            $metric = $shape->key->metric ?? throw new \LogicException('A key has a built-in metric or a template metric.');
            $name = $shape->key->name;
            $times = $this->namedRunValues($allFacts, $metric, $name);

            $rows = [];
            foreach ($cards as $card) {
                foreach ($partitionsOf[(string) $card->id] as $partition) {
                    $rows[] = new MetricRow(
                        $card->id,
                        $card->number,
                        $partition['group'],
                        $card->completedAt,
                        self::cardValue($partition['facts'], $metric, $name, $times),
                        self::keyOf($group, $partition['group']),
                    );
                }
            }
            $result[] = $rows;
        }

        return $result;
    }

    /**
     * Loads what the windows of a refresh need, so the reads of every graph find it in the fact set. A null start
     * loads all time. Each load reads only the part the set lacks.
     */
    public function load(MetricFacts $facts, bool $runs, ?\DateTimeImmutable $runsFrom, bool $cards, ?\DateTimeImmutable $cardsFrom): void
    {
        if ($runs) {
            $this->ensureClosedRuns($facts, $runsFrom);
        }
        if ($cards) {
            $this->ensureFinishedCards($facts, $cardsFrom);
            $this->runsOfCards($facts, array_map(static fn (FinishedCard $card): Uuid => $card->id, $facts->finishedCards ?? []));
        }
    }

    /**
     * Loads the facts that the windows of these queries read, with one start for the run reads and one for the card
     * reads. A query that reads neither, such as a reading query or a formula with a problem, loads nothing.
     *
     * @param list<MetricQueryCommand> $commands
     */
    public function loadFor(MetricFacts $facts, array $commands): void
    {
        $runs = false;
        $runsFrom = null;
        $cards = false;
        $cardsFrom = null;
        foreach ($commands as $command) {
            if (null !== $command->formula?->problem) {
                continue;
            }
            $from = self::earliestStart($command, $facts->to);
            if (MetricUnit::Run === $command->unit) {
                $runsFrom = $runs ? self::earlier($runsFrom, $from) : $from;
                $runs = true;
            } elseif (MetricUnit::Card === $command->unit) {
                $cardsFrom = $cards ? self::earlier($cardsFrom, $from) : $from;
                $cards = true;
            }
        }

        $this->load($facts, $runs, $runsFrom, $cards, $cardsFrom);
    }

    /** The start of the earliest window the query reads, the previous period included. Null is all time. */
    private static function earliestStart(MetricQueryCommand $command, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $from = $command->range->startFrom($now);
        $length = $command->range->length();
        if (null === $from || MetricCompare::Previous !== $command->compare || null === $length) {
            return $from;
        }

        return $from->sub($length);
    }

    /** The earlier of two starts, where null is all time. */
    private static function earlier(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): ?\DateTimeImmutable
    {
        return null === $a || null === $b ? null : min($a, $b);
    }

    private function ensureClosedRuns(MetricFacts $facts, ?\DateTimeImmutable $from): void
    {
        if (null === $facts->closedRuns) {
            $facts->closedRuns = $this->workerRunFacts->findClosedSince($facts->project, $from, false, $facts->to);
            $facts->runsFrom = $from;

            return;
        }
        if (null !== $facts->runsFrom && (null === $from || $from < $facts->runsFrom)) {
            $facts->closedRuns = [...$this->workerRunFacts->findClosedSince($facts->project, $from, false, $facts->runsFrom), ...$facts->closedRuns];
            $facts->runsFrom = $from;
        }
    }

    private function ensureFinishedCards(MetricFacts $facts, ?\DateTimeImmutable $from): void
    {
        if (null === $facts->finishedCards) {
            $facts->finishedCards = $this->finishedCards->finishedCards($facts->project, $from, $facts->to);
            $facts->cardsFrom = $from;

            return;
        }
        if (null !== $facts->cardsFrom && (null === $from || $from < $facts->cardsFrom)) {
            $facts->finishedCards = [...$this->finishedCards->finishedCards($facts->project, $from, $facts->cardsFrom), ...$facts->finishedCards];
            $facts->cardsFrom = $from;
        }
    }

    /**
     * The closed runs of the window with the conditions of WorkerRunFactRepository::findClosedSince(). A null end is
     * the stamp.
     *
     * @return list<WorkerRunFact>
     */
    private function closedRuns(MetricFacts $facts, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, bool $cardsOnly): array
    {
        $to ??= $facts->to;
        if ($to > $facts->to) {
            throw new \LogicException('A window must not end after the stamp of its fact set.');
        }
        $this->ensureClosedRuns($facts, $from);

        return array_values(array_filter($facts->closedRuns ?? [], static function (WorkerRunFact $fact) use ($from, $to, $cardsOnly): bool {
            $time = $fact->endedAt ?? $fact->receivedAt;

            return (null === $from || $time >= $from) && $time < $to && (!$cardsOnly || 'card' === $fact->subjectType);
        }));
    }

    /** @return list<FinishedCard> */
    private function finishedCardsOf(MetricFacts $facts, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $to ??= $facts->to;
        if ($to > $facts->to) {
            throw new \LogicException('A window must not end after the stamp of its fact set.');
        }
        $this->ensureFinishedCards($facts, $from);

        return array_values(array_filter($facts->finishedCards ?? [], static fn (FinishedCard $card): bool => (null === $from || $card->completedAt >= $from) && $card->completedAt < $to));
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, list<WorkerRunFact>> card id => its runs, with a list for every id
     */
    private function runsOfCards(MetricFacts $facts, array $ids): array
    {
        $missing = array_values(array_filter($ids, static fn (Uuid $id): bool => !isset($facts->runsOfCards[(string) $id])));
        if ([] !== $missing) {
            foreach ($missing as $id) {
                $facts->runsOfCards[(string) $id] = [];
            }
            foreach ($this->workerRunFacts->findOfCards($facts->project, $missing) as $fact) {
                $facts->runsOfCards[(string) $fact->subjectId][] = $fact;
            }
        }

        $runs = [];
        foreach ($ids as $id) {
            $runs[(string) $id] = $facts->runsOfCards[(string) $id];
        }

        return $runs;
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

    /** The key of a split by account or by bridge. Any other split has none. */
    private static function keyOf(MetricGroup $group, ?string $name): ?MetricGroupKey
    {
        if (null === $name) {
            return null;
        }

        return match ($group) {
            MetricGroup::Account => new MetricGroupKey(account: $name),
            MetricGroup::Bridge => new MetricGroupKey(bridgeId: $name),
            default => null,
        };
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
            MetricGroup::WakeGap => ColdWake::gapBucket($fact)?->key(),
            MetricGroup::WakeCache => ColdWake::cacheGroup($fact),
            MetricGroup::None, MetricGroup::AccountWindow => null,
        };
    }

    private static function runValue(WorkerRunFact $fact, Metric $metric): int|float|null
    {
        return match ($metric) {
            Metric::SessionUsage => $fact->sessionUsageDelta,
            Metric::WeekUsage => $fact->weekUsageDelta,
            default => self::inDollars(self::rawValue($fact, $metric), $metric),
        };
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
            Metric::PeakContext => $fact->peakContextTokens,
            Metric::Wakes => null === $fact->wakeTrigger ? 0 : 1,
            Metric::ColdWakes => true === ColdWake::of($fact) ? 1 : 0,
            Metric::ColdWakeCacheWriteTokens => true === ColdWake::of($fact) ? $fact->wakeFirstCacheWriteTokens : 0,
            Metric::ColdWakeShare => null === ColdWake::of($fact) ? null : (int) ColdWake::of($fact),
            Metric::Runs, Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls, Metric::BucketTimeEach, Metric::BucketTimeAllEach, Metric::BucketCallsEach, Metric::HarnessEvents, Metric::AccountUsage, Metric::SessionUsage, Metric::WeekUsage => throw new \LogicException(\sprintf('The metric %s has no run value.', $metric->value)),
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
