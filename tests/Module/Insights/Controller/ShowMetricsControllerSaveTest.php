<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\View\MetricChartType;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ShowMetricsControllerSaveTest extends WebTestCase
{
    use InsightsScenario;

    private const string QUERY = '?unit=run&statistic=sum&range=all&bucket=day';

    public function test_the_page_offers_to_save_the_graph_it_draws(): void
    {
        $client = static::createClient();
        $project = $this->projectWithRun('save-form');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $this->path($projectId).self::QUERY);

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-save-graph]');
        self::assertCount(1, $form);
        self::assertSame('post', $form->attr('method'));
        self::assertSame('/projects/'.$projectId.'/analytics/metrics/graphs', $form->attr('action'));
        self::assertSame(
            ['metric' => 'cost', 'unit' => 'run', 'statistic' => 'sum', 'group' => 'none', 'range' => 'all', 'bucket' => 'day', 'compare' => 'off', 'chart' => 'line'],
            self::hidden($form),
        );
        self::assertNotSame('', (string) $form->filter('input[name="saved_graph_form[_token]"]')->attr('value'));
        $name = $form->filter('input[name="saved_graph_form[name]"]');
        self::assertSame('Cost per run, sum, all time, per day', $name->attr('value'));
        self::assertSame('120', $name->attr('maxlength'));
        self::assertSame('Name', trim($form->filter('label[for="'.$name->attr('id').'"]')->text()));
        self::assertSame('Save to dashboard', trim($form->filter('button[data-save-new]')->text()));
        self::assertNull($form->filter('button[data-save-new]')->attr('disabled'));
        self::assertCount(0, $form->filter('[data-save-cap]'));
        self::assertCount(0, $crawler->filter('[data-edit-banner]'));
        self::assertCount(0, $crawler->filter('[data-leave-guard-dirty-value]'));
    }

    public function test_a_page_with_nothing_to_draw_offers_no_save(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('save-empty');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $this->path($projectId));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-metrics-empty]'));
        self::assertCount(0, $crawler->filter('form[data-save-graph]'));
    }

    public function test_a_project_at_the_cap_keeps_the_page_and_disables_the_save(): void
    {
        $client = static::createClient();
        $project = $this->projectWithRun('save-cap');
        for ($i = 0; $i < 12; ++$i) {
            $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $this->path($projectId).self::QUERY);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-metrics-chart] figure'));
        $form = $crawler->filter('form[data-save-graph]');
        self::assertSame('disabled', $form->filter('button[data-save-new]')->attr('disabled'));
        self::assertSame('The Dashboard holds 12 graphs. Remove one to add another.', trim($form->filter('[data-save-cap]')->text()));
    }

    public function test_a_removed_foreign_or_malformed_graph_id_is_ignored(): void
    {
        $client = static::createClient();
        $project = $this->projectWithRun('edit-ignored');
        $removed = $this->savedCostGraph($project, 'Gone', removedAt: new \DateTimeImmutable());
        $other = $this->projectWithRun('edit-other');
        $foreign = $this->savedCostGraph($other, 'Elsewhere');
        $projectId = (string) $project->id;
        $ids = [(string) $removed->id, (string) $foreign->id, 'not-an-id'];
        $this->em()->clear();

        $client->loginUser($project->owner);
        foreach ($ids as $id) {
            $crawler = $client->request(Request::METHOD_GET, $this->path($projectId).self::QUERY.'&graph='.$id);

            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[data-edit-banner]'), $id);
            self::assertCount(1, $crawler->filter('form[data-save-graph] input[name="saved_graph_form[name]"]'), $id);
            self::assertCount(0, $crawler->filter('[data-leave-guard-dirty-value]'), $id);
            self::assertCount(0, $crawler->filter('form[data-metrics-form] input[name="graph"]'), $id);
        }
    }

    public function test_a_saved_graph_alone_fills_the_controls_and_shows_the_edit_buttons(): void
    {
        $client = static::createClient();
        $project = $this->projectWithRun('edit-prefill');
        $graph = $this->savedCostGraph($project, 'Cost <b>of</b> runs');
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $this->path($projectId).'?graph='.$graphId);

        self::assertResponseIsSuccessful();
        $controls = $crawler->filter('form[data-metrics-form]');
        self::assertSame(
            ['metric' => 'cost', 'statistic' => 'sum', 'unit' => 'run', 'group' => 'none', 'range' => 'all', 'bucket' => 'day', 'compare' => 'off', 'chart' => 'line'],
            self::selected($controls),
        );
        self::assertSame($graphId, $controls->filter('input[name="graph"]')->attr('value'));

        $banner = $crawler->filter('[data-edit-banner]');
        self::assertSame('Cost <b>of</b> runs', $banner->filter('strong')->text());
        self::assertStringContainsString('Editing the saved graph', $banner->text());
        self::assertStringContainsString('The Dashboard shows your changes after you save.', $banner->text());
        self::assertSame('/projects/'.$projectId.'/analytics/metrics', $banner->filter('a')->attr('href'));
        self::assertSame('Back to the Dashboard', trim($banner->filter('a')->text()));

        $form = $crawler->filter('form[data-save-graph]');
        self::assertCount(0, $form->filter('input[name="saved_graph_form[name]"]'));
        self::assertNotSame('', (string) $form->filter('input[name="saved_graph_form[_token]"]')->attr('value'));
        self::assertSame('/projects/'.$projectId.'/analytics/metrics/graphs', $form->attr('action'));
        $update = $form->filter('button[data-save-update]');
        self::assertSame('Save changes', trim($update->text()));
        self::assertSame('/projects/'.$projectId.'/analytics/metrics/graphs/'.$graphId, $update->attr('formaction'));
        self::assertNull($update->attr('disabled'));
        self::assertSame('Save as new graph', trim($form->filter('button[data-save-new]')->text()));
        self::assertNull($form->filter('button[data-save-new]')->attr('disabled'));
        self::assertSame('false', $crawler->filter('[data-leave-guard-dirty-value]')->attr('data-leave-guard-dirty-value'));
    }

    public function test_controls_in_the_url_win_over_the_graph_and_mark_it_changed(): void
    {
        $client = static::createClient();
        $project = $this->projectWithRun('edit-dirty');
        $graph = $this->savedCostGraph($project, 'Cost');
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $this->path($projectId).'?graph='.$graphId.'&unit=run&statistic=mean&range=all&bucket=day');

        self::assertResponseIsSuccessful();
        self::assertSame('mean', self::selected($crawler->filter('form[data-metrics-form]'))['statistic']);
        self::assertSame('true', $crawler->filter('[data-leave-guard-dirty-value]')->attr('data-leave-guard-dirty-value'));
        self::assertSame('mean', self::hidden($crawler->filter('form[data-save-graph]'))['statistic']);

        $same = $client->request(Request::METHOD_GET, $this->path($projectId).'?graph='.$graphId.'&unit=run&statistic=sum&range=all&bucket=day&compare=off&chart=line&metric=cost&group=none');
        self::assertSame('false', $same->filter('[data-leave-guard-dirty-value]')->attr('data-leave-guard-dirty-value'));
    }

    public function test_at_the_cap_an_edit_can_save_its_changes_but_not_a_new_graph(): void
    {
        $client = static::createClient();
        $project = $this->projectWithRun('edit-cap');
        $graph = $this->savedCostGraph($project, 'Cost');
        for ($i = 1; $i < 12; ++$i) {
            $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $this->path($projectId).'?graph='.$graphId);

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-save-graph]');
        self::assertNull($form->filter('button[data-save-update]')->attr('disabled'));
        self::assertSame('disabled', $form->filter('button[data-save-new]')->attr('disabled'));
        self::assertSame('The Dashboard holds 12 graphs. Remove one to add another.', trim($form->filter('[data-save-cap]')->text()));
    }

    private function path(string $projectId): string
    {
        return '/projects/'.$projectId.'/analytics/metrics';
    }

    private function projectWithRun(string $name): Project
    {
        $project = $this->scenarioProject($name);
        $run = $this->seedRun($this->em(), $project, cardNumber: 4, endedAt: new \DateTimeImmutable('2026-09-01 10:05:00'));
        $this->seedUsage($this->em(), $run, costUsd: '1.00');

        return $project;
    }

    private function savedCostGraph(Project $project, string $name, ?\DateTimeImmutable $removedAt = null): InsightsSavedGraph
    {
        $em = $this->em();
        $graph = new InsightsSavedGraph(
            $project,
            $name,
            'cost',
            MetricUnit::Run,
            MetricStatistic::Sum,
            MetricGroup::None,
            MetricRange::All,
            MetricBucket::Day,
            MetricCompare::Off,
            MetricChartType::Line,
            SavedGraphWidth::Half,
            true,
            0,
            removedAt: $removedAt,
        );
        $em->persist($graph);
        $em->flush();

        return $graph;
    }

    /** @return array<string, string> setting => value of the hidden fields of the save form */
    private static function hidden(Crawler $form): array
    {
        $values = [];
        $form->filter('input[type="hidden"]')->each(static function (Crawler $input) use (&$values): void {
            if (1 === preg_match('/^saved_graph_form\[(\w+)\]$/', (string) $input->attr('name'), $match) && '_token' !== $match[1]) {
                $values[$match[1]] = (string) $input->attr('value');
            }
        });

        return $values;
    }

    /** @return array<string, string> select name => selected value */
    private static function selected(Crawler $form): array
    {
        $selected = [];
        $form->filter('select')->each(static function (Crawler $select) use (&$selected): void {
            $selected[(string) $select->attr('name')] = (string) $select->filter('option[selected]')->attr('value');
        });

        return $selected;
    }
}
