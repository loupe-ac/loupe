<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Bridge\Metric\MetricGroupKey;
use App\Module\Bridge\Metric\MetricWindow;
use App\Module\Insights\Entity\PublicSeriesLabelKind;
use App\Module\Insights\Repository\PublicSeriesLabelRepository;
use App\Module\Insights\Service\PublicSeriesLabels;
use App\Module\Insights\View\DashboardGraphResult;
use App\Module\Insights\View\MetricChartResolution;
use App\Module\Insights\View\MetricChartType;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PublicSeriesLabelsTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_an_account_series_is_numbered_in_the_order_of_first_sight(): void
    {
        $project = $this->scenarioProject('labels-account');

        $result = $this->apply($project, ['work', 'home'], [new MetricGroupKey(account: 'work'), new MetricGroupKey(account: 'home')]);

        self::assertSame(['Account 1', 'Account 2'], $result->labels);
    }

    public function test_a_number_never_changes_and_a_new_name_gets_the_next_one(): void
    {
        $project = $this->scenarioProject('labels-stable');
        $this->apply($project, ['work', 'home'], [new MetricGroupKey(account: 'work'), new MetricGroupKey(account: 'home')]);

        $result = $this->apply($project, ['aaa', 'home', 'work'], [new MetricGroupKey(account: 'aaa'), new MetricGroupKey(account: 'home'), new MetricGroupKey(account: 'work')]);

        self::assertSame(['Account 3', 'Account 2', 'Account 1'], $result->labels);
    }

    public function test_a_bridge_series_is_numbered_by_the_bridge_id_apart_from_the_accounts(): void
    {
        $project = $this->scenarioProject('labels-bridge');

        $result = $this->apply($project, ['Box / 7', 'work'], [new MetricGroupKey(bridgeId: 'bridge-a'), new MetricGroupKey(account: 'work')]);

        self::assertSame(['Bridge 1', 'Account 1'], $result->labels);
    }

    public function test_an_account_usage_series_holds_the_bridge_the_account_and_the_window(): void
    {
        $project = $this->scenarioProject('labels-usage');

        $result = $this->apply($project, ['Dev / Box 7/work session'], [new MetricGroupKey('bridge-a', 'work', 'session')]);

        self::assertSame(['Bridge 1 / Account 1 session'], $result->labels);
        self::assertStringNotContainsString('Box', $result->labels[0]);
        self::assertStringNotContainsString('work', $result->labels[0]);
    }

    public function test_a_usage_series_shares_the_numbers_of_the_account_and_bridge_series(): void
    {
        $project = $this->scenarioProject('labels-shared');
        $this->apply($project, ['x', 'y'], [new MetricGroupKey(account: 'x'), new MetricGroupKey(account: 'y')]);
        $this->apply($project, ['Box'], [new MetricGroupKey(bridgeId: 'bridge-a')]);

        $result = $this->apply($project, ['usage'], [new MetricGroupKey('bridge-a', 'y', 'week')]);

        self::assertSame(['Bridge 1 / Account 2 week'], $result->labels);
    }

    public function test_a_renamed_bridge_keeps_its_number_because_the_key_is_its_id(): void
    {
        $project = $this->scenarioProject('labels-rename');
        $this->apply($project, ['Old name'], [new MetricGroupKey(bridgeId: 'bridge-a')]);

        $result = $this->apply($project, ['New name', 'Other'], [new MetricGroupKey(bridgeId: 'bridge-a'), new MetricGroupKey(bridgeId: 'bridge-b')]);

        self::assertSame(['Bridge 1', 'Bridge 2'], $result->labels);
    }

    public function test_a_series_with_no_key_keeps_its_label(): void
    {
        $project = $this->scenarioProject('labels-null');

        $result = $this->apply($project, ['claude-opus', 'work'], [null, new MetricGroupKey(account: 'work')]);

        self::assertSame(['claude-opus', 'Account 1'], $result->labels);
    }

    public function test_two_series_with_one_account_name_in_two_projects_number_apart(): void
    {
        $first = $this->scenarioProject('labels-first');
        $second = $this->scenarioProject('labels-second');
        $this->apply($first, ['a', 'work'], [new MetricGroupKey(account: 'a'), new MetricGroupKey(account: 'work')]);

        $result = $this->apply($second, ['work'], [new MetricGroupKey(account: 'work')]);

        self::assertSame(['Account 1'], $result->labels);
    }

    public function test_a_stored_number_is_not_overwritten_by_a_second_insert(): void
    {
        $project = $this->scenarioProject('labels-conflict');
        $repository = $this->repository();
        $repository->insertIfAbsent($project, PublicSeriesLabelKind::Account, 'work', 1);

        $repository->insertIfAbsent($project, PublicSeriesLabelKind::Account, 'work', 5);

        self::assertSame(['work' => 1], $repository->numbersFor($project, PublicSeriesLabelKind::Account));
    }

    public function test_the_result_keeps_everything_but_the_labels(): void
    {
        $project = $this->scenarioProject('labels-rest');
        $keys = [new MetricGroupKey(account: 'work')];
        $source = $this->graphResult(['work'], $keys);

        $result = $this->labels()->apply($project, $source);

        self::assertSame($source->computedAt, $result->computedAt);
        self::assertSame($source->title, $result->title);
        self::assertSame($source->layout, $result->layout);
        self::assertSame($keys, $result->seriesKeys);
        self::assertSame($source->current, $result->current);
    }

    /**
     * @param list<string>          $labels
     * @param list<?MetricGroupKey> $keys
     */
    private function apply(Project $project, array $labels, array $keys): DashboardGraphResult
    {
        return $this->labels()->apply($project, $this->graphResult($labels, $keys));
    }

    /**
     * @param list<string>          $labels
     * @param list<?MetricGroupKey> $keys
     */
    private function graphResult(array $labels, array $keys): DashboardGraphResult
    {
        $now = new \DateTimeImmutable('2026-11-06 10:00:00');

        return new DashboardGraphResult($now, 'Cost', new MetricChartResolution(MetricChartType::Line, MetricChartType::Line, null), null, $labels, $keys, new MetricWindow($now->modify('-30 days'), $now), null);
    }

    private function repository(): PublicSeriesLabelRepository
    {
        $repository = self::getContainer()->get(PublicSeriesLabelRepository::class);
        self::assertInstanceOf(PublicSeriesLabelRepository::class, $repository);

        return $repository;
    }

    private function labels(): PublicSeriesLabels
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return new PublicSeriesLabels($this->em(), $this->repository(), $translator);
    }
}
