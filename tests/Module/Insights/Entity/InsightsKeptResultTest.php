<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Entity;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\ControlResult;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentControl;
use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentGuard;
use App\Module\Bridge\Experiment\ExperimentGuardChange;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalHold;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Metric\Formula\BinaryNode;
use App\Module\Bridge\Metric\Formula\FormulaNode;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricWindow;
use App\Module\Insights\Entity\InsightsKeptResult;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Insights\View\PublicExperimentResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\PhpStanExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\Uid\Uuid;

final class InsightsKeptResultTest extends TestCase
{
    public function test_a_graph_result_survives_a_round_trip(): void
    {
        $at = new \DateTimeImmutable('2026-11-08 12:00:00 UTC');
        $series = new MetricSeries(null, [], new MetricPoint(null, null, 0), [new MetricRow(Uuid::v7(), 1, null, $at, 2.0)]);
        $result = new KeptGraphResult($at, new MetricsQuery(), [$series], [], new MetricWindow($at, $at), null);

        self::assertEquals($result, InsightsKeptResult::decode(InsightsKeptResult::encode($result)));
    }

    public function test_an_experiment_result_with_a_populated_headline_survives_a_round_trip(): void
    {
        $interval = new Interval(-0.4, -0.2, -0.3);
        $metric = new ExperimentMetric('cost', ['a' => $interval], true);
        $arm = new ExperimentArm('a', 1.0, 3);
        $control = new ExperimentControl('cost', 'a', 'any', 0.5, 0.4, 3, 3, ControlResult::Failed);
        $comparison = new ExperimentComparison('b', GoalState::Held, $arm, true, $interval, new GoalHold(ControlResult::Failed, [$control], 1), [new ExperimentGuardChange('cost', 1.0, $interval)]);
        $headline = new ExperimentHeadline(new ExperimentGoal($metric, GoalDirection::Lower), 'a', new Confidence(0.9), $arm, [$comparison], [new ExperimentGuard($metric, 1.0)], [$control]);
        $result = new PublicExperimentResult(new \DateTimeImmutable('2026-11-08 12:00:00 UTC'), 'exp', $headline, [new ExperimentVariant('a', ['m'], 1, 3, 3, 4, 10, 10)], ['cost' => $metric]);

        self::assertEquals($result, InsightsKeptResult::decode(InsightsKeptResult::encode($result)));
    }

    public function test_a_foreign_root_class_is_refused(): void
    {
        self::assertNull(InsightsKeptResult::decode(base64_encode(serialize(new \stdClass()))));
        self::assertNull(InsightsKeptResult::decode(base64_encode(serialize(new \ArrayObject([1])))));
        self::assertNull(InsightsKeptResult::decode(base64_encode(serialize('text'))));
        self::assertNull(InsightsKeptResult::decode('not base64 !'));
    }

    public function test_a_foreign_class_inside_a_result_never_comes_back_as_an_object(): void
    {
        $at = new \DateTimeImmutable('2026-11-08 12:00:00 UTC');
        $good = serialize(new KeptGraphResult($at, new MetricsQuery(), [], [], new MetricWindow($at, $at), null));
        $forged = str_replace('O:'.\strlen(MetricWindow::class).':"'.MetricWindow::class.'"', 'O:8:"Foreign1"', $good);
        self::assertNotSame($good, $forged);

        self::assertNull(InsightsKeptResult::decode(base64_encode($forged)));
    }

    public function test_the_class_list_holds_every_class_a_result_can_reach(): void
    {
        $extractor = new PropertyInfoExtractor([new ReflectionExtractor()], [new PhpStanExtractor(), new ReflectionExtractor()]);
        $allowed = InsightsKeptResult::RESULT_CLASSES;
        $missing = [];
        $seen = [];
        $queue = [KeptGraphResult::class, PublicExperimentResult::class];
        while ([] !== $queue) {
            $class = array_shift($queue);
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;
            if (!\in_array($class, $allowed, true)) {
                $missing[] = $class;
            }
            if (new \ReflectionClass($class)->isInternal()) {
                continue;
            }
            foreach ($extractor->getProperties($class) ?? [] as $property) {
                $type = $extractor->getType($class, $property);
                foreach ($type?->traverse() ?? [] as $part) {
                    $name = $part instanceof ObjectType ? $part->getClassName() : '';
                    if (enum_exists($name) || !(class_exists($name) || interface_exists($name))) {
                        continue;
                    }
                    foreach (self::concrete($name) as $next) {
                        $queue[] = $next;
                    }
                }
            }
        }

        self::assertSame([], $missing, 'Add these classes to InsightsKeptResult::RESULT_CLASSES.');
    }

    public function test_the_guard_walk_finds_the_formula_nodes(): void
    {
        self::assertContains(BinaryNode::class, self::concrete(FormulaNode::class));
    }

    /**
     * @param class-string $class
     *
     * @return list<class-string>
     */
    private static function concrete(string $class): array
    {
        if (!interface_exists($class)) {
            return [$class];
        }
        $found = [];
        $reflection = new \ReflectionClass($class);
        $directory = \dirname($reflection->getFileName() ?: throw new \LogicException('No file for '.$class));
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $candidate = $reflection->getNamespaceName().'\\'.basename($file, '.php');
            if (class_exists($candidate) && is_subclass_of($candidate, $class)) {
                $found[] = $candidate;
            }
        }

        return $found;
    }
}
