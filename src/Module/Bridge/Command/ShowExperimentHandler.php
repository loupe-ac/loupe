<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Bridge\Experiment\ControlEvaluator;
use App\Module\Bridge\Experiment\ExperimentCard;
use App\Module\Bridge\Experiment\ExperimentControl;
use App\Module\Bridge\Experiment\ExperimentHeadlineBuilder;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\LeftOutReason;
use App\Module\Bridge\Experiment\Stats;
use App\Module\Bridge\Metric\CardRequestCounts;
use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Bridge\Metric\TemplateMetricValues;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\BucketRule;
use App\Module\Bridge\Service\BucketRuleSourceInterface;
use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Utils\PageList;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-type KeptCard array{cardId: Uuid, delivered: bool, finished: bool, runs: list<WorkerRun>, facts: list<WorkerRunFact>, costMicros: ?int}
 * @phpstan-type ControlSpec array{metric: string, variant: string, expect: string, share: float}
 */
final readonly class ShowExperimentHandler
{
    public const int PER_PAGE = 20;

    /** The metrics of an experiment whose rule declares none, in display order. The ones the project's template does not declare drop out. */
    public const array DEFAULT_GUARDS = [ExperimentMetric::MERGE_RATE, ExperimentMetric::FIX_ROUNDS];

    public const array DEFAULT_METRICS = [
        ExperimentMetric::MERGE_RATE,
        ExperimentMetric::STOP_RATE,
        ExperimentMetric::FIX_ROUNDS,
        ExperimentMetric::COST,
        ExperimentMetric::OUTPUT_TOKENS,
        ExperimentMetric::HOURS_TO_MERGE,
    ];

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private ExperimentPinRepository $experimentPins,
        private ExperimentDefinitionRepository $experimentDefinitions,
        private WorkerRunFactRepository $workerRunFacts,
        private CardReportSourceInterface $cardReports,
        private CardTitleSourceInterface $cardTitles,
        private TemplateMetricValues $templateValues,
        private TemplateMetrics $templates,
        private MetricRowSource $metricRows,
        private WorkerRunBucketTimeRepository $workerRunBucketTimes,

        /** @var iterable<BucketRuleSourceInterface> */
        #[AutowireIterator('app.bucket_rule_source')]
        private iterable $bucketRuleSources,
    ) {
    }

    public function __invoke(ShowExperimentCommand $command): ?ExperimentReportView
    {
        $project = $command->project;
        $experiment = $command->experiment;

        /** @var array<string, Uuid> $cardIds */
        $cardIds = [];
        /** @var array<string, string> $pinVariants */
        $pinVariants = [];
        foreach ($this->experimentPins->findOfExperiment($project, $experiment) as $pin) {
            $cardIds[(string) $pin->cardId] = $pin->cardId;
            $pinVariants[(string) $pin->cardId] = $pin->variant;
        }
        /** @var array<string, non-empty-list<WorkerRun>> $experimentRuns oldest first */
        $experimentRuns = [];
        /** @var array<string, list<WorkerRun>> $plainRuns runs of no experiment or of another one */
        $plainRuns = [];
        /** @var array<string, ?string> $models variant => model of its latest run */
        $models = [];
        foreach ($this->workerRuns->findWorkerRunsOfExperimentCards($project, $experiment) as $run) {
            $id = (string) $run->subjectId;
            if ($experiment === $run->experiment) {
                $cardIds[$id] = $run->subjectId;
                $experimentRuns[$id][] = $run;
                if (null !== $run->variant) {
                    $models[$run->variant] = $run->requestedModel;
                }
            } else {
                $plainRuns[$id][] = $run;
            }
        }
        if ([] === $cardIds) {
            return null;
        }

        $ids = array_values($cardIds);
        $templates = $this->templates->forProject($project->id ?? throw new \LogicException('A project has an id here.'));
        $historyStart = $this->cardReports->historyStartFor($project);
        $delivered = $templates->delivered()?->key;
        $outcomes = $this->cardReports->outcomesOfCards($project, $ids);
        $fixCounts = $this->templateValues->countsOf($ids);
        $columns = $this->cardReports->columnsFor($project, $ids);
        $titles = $this->cardTitles->titlesFor($project, $ids);
        /** @var array<string, list<WorkerRunFact>> $facts */
        $facts = [];
        foreach ($this->workerRunFacts->findOfExperimentCards($project, $experiment) as $fact) {
            $facts[(string) $fact->subjectId][] = $fact;
        }
        $definition = $this->experimentDefinitions->findOneBy(['project' => $project, 'experiment' => $experiment]);
        $weights = null === $definition ? [] : array_column($definition->weights, 'weight', 'name');
        // An empty declaration names no metric, so the defaults apply.
        $declared = $definition?->metrics ?: null;
        $shapes = [];
        $unknownMetrics = [];
        foreach ($declared ?? self::DEFAULT_METRICS as $key) {
            $shape = self::shapeOf($key, $templates);
            if (null !== $shape) {
                $shapes[$key] = $shape;
            } elseif (null !== $declared && !\in_array($key, $unknownMetrics, true)) {
                $unknownMetrics[] = $key;
            }
        }

        $variantNames = array_merge(array_keys($weights), array_values($pinVariants), array_keys($models));

        /** @var list<array{card: ExperimentCard, lastRunAt: ?\DateTimeImmutable}> $cards */
        $cards = [];
        /** @var array<string, list<KeptCard>> $kept variant => kept cards, a null cost when it is unknown */
        $kept = [];
        foreach ($cardIds as $id => $cardId) {
            $own = $experimentRuns[$id] ?? [];
            $first = $own[0] ?? null;
            $last = [] === $own ? null : $own[\count($own) - 1];
            // A pin can switch before a run reports it, so the runs name the variant that did the work.
            $variant = null === $first ? $pinVariants[$id] ?? null : $first->variant;
            $leftOut = self::leftOut($own, $plainRuns[$id] ?? [], $pinVariants[$id] ?? null, $historyStart);
            $isDelivered = null !== $delivered && \in_array($delivered, $outcomes[$id] ?? [], true);
            $column = $columns[$id] ?? null;
            $ownFacts = $facts[$id] ?? [];
            $costMicros = MetricRowSource::cardSum($ownFacts, Metric::Cost);

            $cards[] = [
                'card' => new ExperimentCard(
                    cardId: $cardId,
                    number: $last?->cardNumber,
                    title: $titles[$id] ?? null,
                    variant: $variant,
                    column: $column,
                    runs: \count($own),
                    fixRounds: $fixCounts->matching($id, new CountFilter(role: 'fix')),
                    costMicros: $costMicros,
                    leftOut: $leftOut,
                ),
                'lastRunAt' => $last?->receivedAt,
            ];
            if ([] === $leftOut && null !== $variant) {
                $kept[$variant][] = [
                    'cardId' => $cardId,
                    'delivered' => $isDelivered,
                    'finished' => $isDelivered || true === $column?->terminal,
                    'runs' => $own,
                    'facts' => $ownFacts,
                    'costMicros' => $costMicros,
                ];
            }
        }

        $variantNames = array_values(array_unique(array_map(strval(...), $variantNames)));
        sort($variantNames, \SORT_STRING);

        $variants = [];
        $finished = [];
        foreach ($variantNames as $name) {
            $rows = $kept[$name] ?? [];
            $finished[$name] = \count(array_filter($rows, static fn (array $row): bool => $row['finished']));
            $variants[] = new ExperimentVariant(
                name: $name,
                model: $models[$name] ?? null,
                weight: $weights[$name] ?? null,
                cards: \count($rows),
                finishedCards: $finished[$name],
                runs: array_sum(array_map(static fn (array $row): int => \count($row['runs']), $rows)),
                costMicros: self::totalCost($rows),
            );
        }

        // The goal and the guards are computed even when the entry's metrics leave them out.
        [$goalKey, $direction, $guardKeys, $unknownGuards, $goalProblem, $controlSpecs] = self::headlineSpec($definition?->headline, $templates);
        $wanted = $shapes;
        foreach ([$goalKey, ...$guardKeys] as $key) {
            $shape = self::shapeOf($key, $templates);
            if (null !== $shape) {
                $wanted[$key] ??= $shape;
            }
        }
        $computed = $command->withMetrics ? $this->metrics($project, $experiment, $variantNames, $kept, $finished, $fixCounts, array_values($wanted)) : [];
        $metrics = [];
        foreach ([] === $computed ? [] : array_keys($shapes) as $key) {
            $metrics[$key] = $computed[$key];
        }

        $filtered = array_values(array_filter($cards, static fn (array $row): bool => (null === $command->variant || ($row['card']->included() && $command->variant === $row['card']->variant))
            && (!$command->leftOutOnly || !$row['card']->included())));
        // The latest run first, and a card with a pin and no run last.
        usort($filtered, static fn (array $a, array $b): int => [null === $a['lastRunAt'], $b['lastRunAt'], (string) $a['card']->cardId] <=> [null === $b['lastRunAt'], $a['lastRunAt'], (string) $b['card']->cardId]);

        $total = \count($filtered);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        // A page near PHP_INT_MAX overflows the offset multiplication to a float.
        $page = min(max(1, $command->page), intdiv(\PHP_INT_MAX, self::PER_PAGE));
        $included = \count(array_filter($cards, static fn (array $row): bool => $row['card']->included()));

        return new ExperimentReportView(
            project: $project,
            experiment: $experiment,
            headline: [] === $computed ? null : ExperimentHeadlineBuilder::build(
                $experiment,
                $variantNames,
                $finished,
                $computed[$goalKey] ?? new ExperimentMetric($goalKey, [], false),
                $direction,
                array_map(static fn (string $key): ExperimentMetric => $computed[$key], $guardKeys),
                $goalProblem,
                $command->withMetrics ? $this->controls($project, $controlSpecs, $templates, $kept, $finished) : [],
            ),
            variants: $variants,
            metrics: $metrics,
            declaredMetrics: $declared,
            unknownMetrics: $unknownMetrics,
            unknownGuards: $unknownGuards,
            includedCards: $included,
            leftOutCards: \count($cards) - $included,
            cards: array_column(\array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE), 'card'),
            filteredTotal: $total,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
            clampedPage: PageList::clampedPage($page, $total, self::PER_PAGE),
            page: $page,
            variantFilter: $command->variant,
            leftOutOnly: $command->leftOutOnly,
        );
    }

    /**
     * Judges each control on the finished, kept cards of its variant. The value of a card is the one the Metrics table uses for it, except that a control reads every finished card and not the delivered ones alone. A stop rate counts the stopped runs of the card.
     *
     * @param list<ControlSpec>             $specs
     * @param array<string, list<KeptCard>> $kept
     * @param array<string, int>            $finished
     *
     * @return list<ExperimentControl>
     */
    private function controls(Project $project, array $specs, TemplateMetricSet $templates, array $kept, array $finished): array
    {
        $values = [];
        $labels = [];
        $buckets = null;
        foreach (array_unique(array_column($specs, 'metric')) as $key) {
            $shape = self::shapeOf($key, $templates);
            $isBucket = null !== $shape && null !== $shape->key->name && Metric::HarnessEvents !== $shape->key->metric;
            if (null === $shape || ($isBucket && !\in_array($shape->key->name, $buckets ??= $this->bucketNames($project), true))) {
                $values[$key] = null;
                continue;
            }
            $values[$key] = $this->controlValues($project, $shape, $kept);
            if (null !== $shape->template) {
                $labels[$key] = $shape->template->label;
            }
        }

        return ControlEvaluator::evaluate($specs, $values, $finished, $labels);
    }

    /** @return list<string> the buckets the rules of the project name, the fallback bucket, and the buckets with stored rows */
    private function bucketNames(Project $project): array
    {
        $names = [BucketRule::FALLBACK, ...$this->workerRunBucketTimes->findBucketNamesOfProject($project)];
        foreach ($this->bucketRuleSources as $source) {
            foreach ($source->rulesFor($project) as $rule) {
                $names[] = $rule->bucket;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, list<KeptCard>> $kept
     *
     * @return array<string, list<int|float>> variant => the values of its finished cards that have one
     */
    private function controlValues(Project $project, MetricShape $shape, array $kept): array
    {
        $rows = array_map(static fn (array $cards): array => array_values(array_filter($cards, static fn (array $card): bool => $card['finished'])), $kept);
        $template = $shape->template;
        if (null !== $template) {
            $ids = array_merge([], ...array_map(static fn (array $cards): array => array_column($cards, 'cardId'), array_values($rows)));
            $byCard = $this->templateValues->valuesOf($project, $ids, $template);
            $value = static fn (array $card): int|float|null => MetricType::Rate === $template->type ? (int) ($byCard[$card['cardId']->toRfc4122()] ?? 0) : ($byCard[$card['cardId']->toRfc4122()] ?? null);
        } else {
            $of = $shape->key->metric ?? throw new \LogicException('A built-in key has a metric.');
            $named = [];
            if (null !== $shape->key->name) {
                $facts = array_merge([], ...array_map(static fn (array $cards): array => array_merge([], ...array_column($cards, 'facts')), array_values($rows)));
                $named = $this->metricRows->namedRunValues($facts, $of, $shape->key->name);
            }
            $value = match ($of) {
                Metric::StopRate => static function (array $card): ?int {
                    $closed = array_filter($card['runs'], static fn (WorkerRun $run): bool => $run->state->isOutcome());

                    return [] === $closed ? null : \count(array_filter($closed, static fn (WorkerRun $run): bool => $run->state->isStop()));
                },
                Metric::Runs => static fn (array $card): int => \count($card['runs']),
                Metric::Cost => static fn (array $card): ?float => null === $card['costMicros'] ? null : $card['costMicros'] / 1_000_000,
                Metric::InputTokens, Metric::OutputTokens, Metric::CacheReadTokens, Metric::CacheWriteTokens, Metric::Duration => static fn (array $card): ?int => MetricRowSource::cardSum($card['facts'], $of),
                Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls, Metric::HarnessEvents => static fn (array $card): ?int => MetricRowSource::namedCardSum($card['facts'], $named),
            };
        }

        return array_map(static fn (array $cards): array => array_values(array_filter(array_map($value, $cards), static fn (int|float|null $item): bool => null !== $item)), $rows);
    }

    /**
     * Null when no kept card reported usage, so the table does not show a cost of zero.
     *
     * @param list<array{costMicros: ?int}> $rows
     */
    private static function totalCost(array $rows): ?int
    {
        $costs = array_filter(array_column($rows, 'costMicros'), static fn (?int $cost): bool => null !== $cost);

        return [] === $costs ? null : array_sum($costs);
    }

    /**
     * @param list<WorkerRun> $experimentRuns oldest first
     * @param list<WorkerRun> $plainRuns
     *
     * @return list<LeftOutReason>
     */
    private static function leftOut(array $experimentRuns, array $plainRuns, ?string $pinVariant, ?\DateTimeImmutable $historyStart): array
    {
        $first = $experimentRuns[0] ?? null;
        $variants = array_map(static fn (WorkerRun $run): ?string => $run->variant, $experimentRuns);
        if (null !== $first) {
            $variants[] = $pinVariant;
        }
        $variants = array_unique(array_filter($variants, static fn (?string $variant): bool => null !== $variant));

        $reasons = [];
        if ([] !== array_filter($experimentRuns, static fn (WorkerRun $run): bool => null !== $run->switchedFrom)) {
            $reasons[] = LeftOutReason::Switched;
        }
        if (\count($variants) > 1) {
            $reasons[] = LeftOutReason::Mixed;
        }
        if (null !== $first && [] !== array_filter($plainRuns, static fn (WorkerRun $run): bool => $run->workKind === $first->workKind && $run->receivedAt < $first->receivedAt)) {
            $reasons[] = LeftOutReason::BeforeTest;
        }
        if (null !== $first && (null === $historyStart || $first->receivedAt < $historyStart)) {
            $reasons[] = LeftOutReason::NoHistory;
        }
        if (null === $first) {
            $reasons[] = LeftOutReason::NoRun;
        }

        return $reasons;
    }

    /**
     * @param list<string>                  $variantNames
     * @param array<string, list<KeptCard>> $kept
     * @param array<string, int>            $finished
     * @param list<MetricShape>             $shapes       the metrics to compute
     *
     * @return array<string, ExperimentMetric> shape key => metric
     */
    private function metrics(Project $project, string $experiment, array $variantNames, array $kept, array $finished, CardRequestCounts $counts, array $shapes): array
    {
        $delivered = [];
        $sampleIds = [];
        foreach ($variantNames as $name) {
            $delivered[$name] = array_values(array_filter($kept[$name] ?? [], static fn (array $row): bool => $row['delivered']));
            foreach ($kept[$name] ?? [] as $row) {
                if ($row['finished']) {
                    $sampleIds[] = $row['cardId'];
                }
            }
        }

        // A sample gives the range and the card count that the floor of a clear answer reads.
        $metric = static function (string $key, ?MetricShape $shape, callable $sample, array $parts = [], ?string $label = null) use ($variantNames): ExperimentMetric {
            $byVariant = [];
            $cardCounts = [];
            $samples = [];
            foreach ($variantNames as $name) {
                [$byVariant[$name], $cardCounts[$name], $samples[$name]] = $sample($name);
            }

            return new ExperimentMetric($key, $byVariant, self::clear($variantNames, $byVariant, $cardCounts), array_values($parts), $shape, $label, $samples);
        };
        $bootstrap = static fn (MetricValueType $of, string $key, callable $value): \Closure => static function (string $name) use ($of, $key, $value, $delivered, $experiment): array {
            $values = array_values(array_filter(array_map($value, $delivered[$name]), static fn (int|float|null $item): bool => null !== $item));

            return [self::interval($of, $experiment.':'.$key.':'.$name, $values), \count($values), $values];
        };

        $namedValues = [];
        $keptFacts = array_merge([], ...array_map(static fn (array $row): array => $row['facts'], array_merge([], ...array_values($kept))));
        foreach ($shapes as $shape) {
            if (null !== $shape->key->name) {
                $namedValues[$shape->key->key()] = $this->metricRows->namedRunValues($keptFacts, $shape->key->metric ?? throw new \LogicException('A named key has a built-in metric.'), $shape->key->name);
            }
        }

        $metrics = [];
        foreach ($shapes as $shape) {
            $key = $shape->key->key();
            $template = $shape->template;
            if (null !== $template) {
                $metrics[$key] = $this->templateMetric($project, $template, $shape, $metric, $bootstrap, $kept, $finished, $delivered, $sampleIds, $counts, $experiment, $variantNames);
                continue;
            }

            $of = $shape->key->metric ?? throw new \LogicException('A built-in key has a metric.');
            $metrics[$key] = match ($of) {
                Metric::StopRate => $metric($key, $shape, static function (string $name) use ($kept, $finished, $experiment, $key): array {
                    $closed = array_filter(array_merge(...array_column($kept[$name] ?? [], 'runs')), static fn (WorkerRun $run): bool => $run->state->isOutcome());
                    $values = array_values(array_map(static fn (WorkerRun $run): int => $run->state->isStop() ? 1 : 0, $closed));

                    return [self::interval(MetricValueType::Ratio, $experiment.':'.$key.':'.$name, $values), $finished[$name], $values];
                }),
                Metric::Runs => $metric($key, $shape, $bootstrap(MetricValueType::Count, $key, static fn (array $row): int => \count($row['runs']))),
                Metric::Cost => $metric($key, $shape, $bootstrap(MetricValueType::Money, $key, static fn (array $row): ?float => null === $row['costMicros'] ? null : $row['costMicros'] / 1_000_000)),
                Metric::InputTokens, Metric::OutputTokens, Metric::CacheReadTokens, Metric::CacheWriteTokens, Metric::Duration => $metric($key, $shape, $bootstrap($of->valueType(), $key, static fn (array $row): ?int => MetricRowSource::cardSum($row['facts'], $of))),
                Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls, Metric::HarnessEvents => $metric($key, $shape, $bootstrap($of->valueType(), $key, static fn (array $row): ?int => MetricRowSource::namedCardSum($row['facts'], $namedValues[$key] ?? []))),
            };
        }

        return $metrics;
    }

    /**
     * A rate reads the finished cards. A count and a duration read the delivered cards that have a value. A count that splits adds one row per value.
     *
     * @param list<string>                  $variantNames
     * @param array<string, list<KeptCard>> $kept
     * @param array<string, int>            $finished
     * @param array<string, list<KeptCard>> $delivered
     * @param list<Uuid>                    $sampleIds    the finished cards of every variant
     */
    private function templateMetric(Project $project, TemplateMetric $template, MetricShape $shape, callable $metric, callable $bootstrap, array $kept, array $finished, array $delivered, array $sampleIds, CardRequestCounts $counts, string $experiment, array $variantNames): ExperimentMetric
    {
        $values = $this->templateValues->valuesOf($project, $sampleIds, $template);
        $key = $template->key;
        $value = static fn (array $row): int|float|null => $values[$row['cardId']->toRfc4122()] ?? null;

        if (MetricType::Rate === $template->type) {
            return $metric($key, $shape, static function (string $name) use ($kept, $finished, $experiment, $key, $value): array {
                $sample = array_values(array_map(static fn (array $row): int => (int) $value($row), array_filter($kept[$name] ?? [], static fn (array $row): bool => $row['finished'])));

                return [self::interval(MetricValueType::Ratio, $experiment.':'.$key.':'.$name, $sample), $finished[$name], $sample];
            }, [], $template->label);
        }

        $parts = [];
        if (null !== $template->splitBy && null !== $template->filter) {
            $field = $template->splitBy;
            $filter = $template->filter;
            $split = [];
            foreach ($variantNames as $name) {
                foreach ($delivered[$name] as $row) {
                    foreach (array_keys($counts->split($row['cardId']->toRfc4122(), $filter, $field)) as $part) {
                        $split[$part] = true;
                    }
                }
            }
            $partKeys = array_map(strval(...), array_keys($split));
            sort($partKeys, \SORT_STRING);
            foreach ($partKeys as $part) {
                $parts[] = $metric(
                    $part,
                    null,
                    $bootstrap(MetricValueType::Count, $key.':'.$part, static fn (array $row): int => $counts->split($row['cardId']->toRfc4122(), $filter, $field)[$part] ?? 0),
                    [],
                    'reason' === $field ? null : $part,
                );
            }
        }

        return $metric($key, $shape, $bootstrap($shape->valueType(), $key, $value), $parts, $template->label);
    }

    /** Null for a key that no metric has. */
    private static function shapeOf(string $key, TemplateMetricSet $templates): ?MetricShape
    {
        $parsed = MetricKey::tryParse($key, $templates);
        if (null === $parsed) {
            return null;
        }

        return MetricShape::of($parsed, $templates);
    }

    /**
     * A ratio counts successes, so it takes a Wilson range. Any other value takes a bootstrap of its mean.
     *
     * @param list<int|float> $values
     */
    private static function interval(MetricValueType $type, string $seed, array $values): ?Interval
    {
        return MetricValueType::Ratio === $type
            ? Stats::wilson((int) array_sum($values), \count($values))
            : Stats::bootstrapMean($values, $seed);
    }

    /**
     * @param list<string>             $variantNames
     * @param array<string, ?Interval> $byVariant
     * @param array<string, int>       $counts       the cards behind each range
     */
    private static function clear(array $variantNames, array $byVariant, array $counts): bool
    {
        if (\count($variantNames) < 2) {
            return false;
        }
        [$a, $b] = $variantNames;
        $intervalA = $byVariant[$a] ?? null;
        $intervalB = $byVariant[$b] ?? null;

        return null !== $intervalA && null !== $intervalB && Stats::isClear($intervalA, $intervalB, $counts[$a] ?? 0, $counts[$b] ?? 0);
    }

    /**
     * The goal metric, its direction, and the guards. Without a goal the cost is the goal, and lower is better. A goal that no metric has, or that has no better way, comes back with the state that says so, and names no winner. A guard that no metric has falls out and is listed. Without guards the default guards apply, less the goal and the ones the template does not declare.
     *
     * @param array{goal?: array{metric: string, better?: string}, guards?: list<string>, controls?: list<array{metric: string, variant: string, expect: string, share: float}>}|null $headline
     *
     * @return array{string, ?GoalDirection, list<string>, list<string>, ?GoalState, list<ControlSpec>} goal key, direction, guard keys, unknown guard keys, the problem of the goal, the controls
     */
    private static function headlineSpec(?array $headline, TemplateMetricSet $templates): array
    {
        $goalKey = ExperimentMetric::COST;
        $direction = GoalDirection::Lower;
        $problem = null;
        if (isset($headline['goal'])) {
            $goalKey = $headline['goal']['metric'];
            $direction = GoalDirection::tryFrom($headline['goal']['better'] ?? '');
            if (null === self::shapeOf($goalKey, $templates)) {
                $problem = GoalState::UnknownMetric;
            } elseif (null === $direction) {
                $problem = GoalState::NoDirection;
            }
        }

        $guardKeys = [];
        $unknown = [];
        foreach ($headline['guards'] ?? self::DEFAULT_GUARDS as $key) {
            if ($key === $goalKey && !isset($headline['guards'])) {
                continue;
            }
            if (null !== self::shapeOf($key, $templates)) {
                $guardKeys[$key] = $key;
            } elseif (isset($headline['guards'])) {
                $unknown[$key] = $key;
            }
        }

        return [$goalKey, $direction, array_values($guardKeys), array_values($unknown), $problem, $headline['controls'] ?? []];
    }
}
