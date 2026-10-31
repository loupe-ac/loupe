<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ShowMetricsControllerTest extends WebTestCase
{
    use BridgeScenario;

    public function test_a_project_with_no_rows_shows_the_form_at_its_defaults_and_the_empty_state(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-empty@example.com');
        $projectId = (string) $this->project($em, $owner, 'Empty metrics')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-metrics-form]');
        self::assertCount(1, $form);
        self::assertSame('/projects/'.$projectId.'/analytics/metrics', $form->attr('action'));
        self::assertSame('get', $form->attr('method'));
        self::assertSame(
            ['metric' => 'cost', 'statistic' => 'median', 'unit' => 'card', 'group' => 'none', 'range' => 'ninety-days', 'bucket' => 'week', 'compare' => 'off', 'chart' => 'line'],
            self::selected($form),
        );
        self::assertSame(['run', 'card'], self::options($form, 'unit'));
        self::assertSame('Cost', trim($form->filter('select[name="metric"] option[value="cost"]')->text()));
        self::assertCount(13, $form->filter('select[name="metric"] option'));
        self::assertSame(['Metric', 'Statistic', 'Unit', 'Group', 'Range', 'Bucket', 'Compare to', 'Chart'], $form->filter('label')->each(static fn (Crawler $label): string => trim($label->text())));
        self::assertCount(1, $form->filter('noscript'));

        $empty = $crawler->filter('[data-metrics-empty]');
        self::assertCount(1, $empty);
        self::assertSame('No runs are recorded yet', trim($empty->filter('h2')->text()));
        self::assertCount(0, $crawler->filter('[data-metrics-chart]'));
        self::assertCount(0, $crawler->filter('[data-metrics-note]'));
    }

    public function test_the_form_offers_only_what_the_metric_allows_and_corrects_a_refused_value(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-allowed@example.com');
        $projectId = (string) $this->project($em, $owner, 'Allowed metrics')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=stop-rate&unit=card&statistic=p90&group=model');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-metrics-form]');
        self::assertSame(['run'], self::options($form, 'unit'));
        self::assertSame(['mean', 'count'], self::options($form, 'statistic'));
        self::assertSame(['metric' => 'stop-rate', 'statistic' => 'mean', 'unit' => 'run', 'group' => 'model'], array_slice(self::selected($form), 0, 4));
    }

    public function test_run_rows_fill_the_title_the_line_and_the_table(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-runs@example.com');
        $project = $this->project($em, $owner, 'Run metrics');
        $first = $this->seedRun($em, $project, cardNumber: 4, endedAt: new \DateTimeImmutable('2026-09-01 10:05:00'));
        $this->seedUsage($em, $first, costUsd: '1.00');
        $second = $this->seedRun($em, $project, cardNumber: 5, endedAt: new \DateTimeImmutable('2026-09-02 10:05:00'));
        $this->seedUsage($em, $second, costUsd: '3.00');
        $this->seedRun($em, $project, cardNumber: 6, endedAt: new \DateTimeImmutable('2026-09-03 10:05:00'));
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&statistic=sum&range=all&bucket=day');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-metrics-empty]'));

        $chart = $crawler->filter('[data-metrics-chart]');
        self::assertCount(1, $chart);
        self::assertSame('Cost per run, sum, all time, per day', trim($chart->filter('[data-metrics-title]')->text()));
        self::assertCount(1, $chart->filter('figure.lp-chart--line'));
        self::assertCount(0, $chart->filter('.lp-chart__legend'));
        self::assertCount(2, $chart->filter('.lp-chart__dot'));
        $targets = $chart->filter('.lp-chart__target');
        self::assertCount(2, $targets);
        self::assertSame('Sep 1: Cost $1.00', $targets->first()->attr('aria-label'));
        self::assertSame(['0', '0'], $targets->each(static fn (Crawler $target): string => (string) $target->attr('tabindex')));

        $rows = $chart->filter('.lp-data-table__row');
        self::assertCount(1, $rows);
        self::assertSame(['Cost', '$4.00'], $rows->filter('.lp-data-table__cell')->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    public function test_a_count_reads_as_a_number_on_a_ratio_metric(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-count@example.com');
        $project = $this->project($em, $owner, 'Count metrics');
        $this->seedRun($em, $project, exitCode: 0);
        $this->seedRun($em, $project, exitCode: 1);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=stop-rate&statistic=count&range=all');

        self::assertResponseIsSuccessful();
        self::assertSame('2', trim($crawler->filter('[data-metrics-chart] .lp-data-table__row .lp-data-table__cell--end')->text()));
        $ticks = $crawler->filter('[data-metrics-chart] .lp-chart__tick[text-anchor="end"]')->each(static fn (Crawler $tick): string => trim($tick->text()));
        self::assertSame(['0', '1', '2', '3', '4'], $ticks);
    }

    public function test_a_grouped_query_draws_one_line_per_group_with_a_legend_and_a_row_each(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-groups@example.com');
        $project = $this->project($em, $owner, 'Grouped metrics');
        $this->seedRun($em, $project, workKind: 'plan');
        $this->seedRun($em, $project, workKind: 'review');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&metric=duration&group=stage&range=all');

        self::assertResponseIsSuccessful();
        self::assertSame(['plan', 'review'], $crawler->filter('[data-metrics-chart] .lp-chart__legend li')->each(static fn (Crawler $item): string => trim($item->text())));
        $rows = $crawler->filter('[data-metrics-chart] .lp-data-table__row');
        self::assertSame(['plan', 'review'], $rows->each(static fn (Crawler $row): string => trim($row->filter('.lp-data-table__cell')->first()->text())));
        self::assertSame(['5 min', '5 min'], $rows->each(static fn (Crawler $row): string => trim($row->filter('.lp-data-table__cell--end')->first()->text())));
        self::assertCount(2, $crawler->filter('[data-metrics-chart] path.lp-chart__line'));
    }

    public function test_a_bridge_series_shows_the_label_of_its_bridge(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-bridges@example.com');
        $project = $this->project($em, $owner, 'Bridge metrics');
        $named = $this->seedBridge($em, $owner, Uuid::fromString('0199a000-0000-7000-8000-000000000001'));
        $named->name = 'studio-mac';
        $em->flush();
        $unknown = Uuid::fromString('0199a000-0000-7000-8000-0000000000ff');
        $this->seedRun($em, $project, bridgeId: $named->id);
        $this->seedRun($em, $project, bridgeId: $unknown);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&metric=duration&group=bridge&range=all');

        self::assertResponseIsSuccessful();
        self::assertSame(['studio-mac', '0000000000ff'], $crawler->filter('[data-metrics-chart] .lp-chart__legend li')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame(['studio-mac', '0000000000ff'], $crawler->filter('[data-metrics-chart] .lp-data-table__row')->each(static fn (Crawler $row): string => trim($row->filter('.lp-data-table__cell')->first()->text())));
    }

    public function test_the_picker_lists_one_option_for_each_bucket_with_data(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-bucket-picker@example.com');
        $project = $this->project($em, $owner, 'Bucket picker');
        $this->seedBucketTimes($em, $this->seedRun($em, $project), ['tests' => 10, 'git' => 5]);
        $this->seedBucketTimes($em, $this->seedRun($em, $project), ['tests' => 20]);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics');

        self::assertResponseIsSuccessful();
        $select = $crawler->filter('form[data-metrics-form] select[name="metric"]');
        self::assertCount(13, $select->children('option'));
        $groups = $select->filter('optgroup');
        self::assertCount(3, $groups);
        self::assertSame(['Time buckets', 'Time buckets, subagents included', 'Calls in time buckets'], $groups->each(static fn (Crawler $group): string => (string) $group->attr('label')));
        $group = $groups->eq(0);
        self::assertSame(['bucket-time:git', 'bucket-time:tests'], $group->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['Time in git', 'Time in tests'], $group->filter('option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame(['bucket-time-all:git', 'bucket-time-all:tests'], $groups->eq(1)->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['Calls in git', 'Calls in tests'], $groups->eq(2)->filter('option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertCount(0, $select->filter('optgroup option[selected]'));
        self::assertSame('cost', $select->filter('option[selected]')->attr('value'));
    }

    public function test_the_picker_lists_the_harness_event_kinds_in_their_own_group_largest_total_first(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-events-picker@example.com');
        $project = $this->project($em, $owner, 'Events picker');
        $first = $this->seedRun($em, $project);
        $first->harnessEvents = ['diagnostics' => 3, 'hook_success' => 10];
        $second = $this->seedRun($em, $project);
        $second->harnessEvents = ['diagnostics' => 4];
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics');

        self::assertResponseIsSuccessful();
        $group = $crawler->filter('form[data-metrics-form] select[name="metric"] optgroup');
        self::assertCount(1, $group);
        self::assertSame('Harness events', $group->attr('label'));
        self::assertSame(['harness-events:hook_success', 'harness-events:diagnostics'], $group->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['Harness events: hook_success', 'Harness events: diagnostics'], $group->filter('option')->each(static fn (Crawler $option): string => trim($option->text())));
    }

    public function test_a_selected_harness_event_kind_with_no_data_stays_in_the_picker(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-events-selected@example.com');
        $project = $this->project($em, $owner, 'Events selected');
        $this->seedRun($em, $project);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=harness-events:diagnostics');

        self::assertResponseIsSuccessful();
        $selected = $crawler->filter('select[name="metric"] option[selected]');
        self::assertCount(1, $selected);
        self::assertSame('harness-events:diagnostics', $selected->attr('value'));
        self::assertSame('Harness events: diagnostics', trim($selected->text()));
    }

    public function test_a_bucket_metric_shows_the_time_of_its_bucket_and_leaves_a_run_with_no_data_out(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-bucket-rows@example.com');
        $project = $this->project($em, $owner, 'Bucket rows');
        $this->seedBucketTimes($em, $this->seedRun($em, $project, cardNumber: 4, endedAt: new \DateTimeImmutable('2026-09-03 10:05:00')), ['tests' => 4000, 'git' => 1000]);
        $this->seedBucketTimes($em, $this->seedRun($em, $project, cardNumber: 5, endedAt: new \DateTimeImmutable('2026-09-02 10:05:00')), ['git' => 700]);
        $this->seedRun($em, $project, cardNumber: 6, endedAt: new \DateTimeImmutable('2026-09-01 10:05:00'));
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=bucket-time:tests&unit=run&statistic=sum&range=all&bucket=day');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-metrics-form]');
        self::assertSame(['bucket-time:tests'], $form->filter('select[name="metric"] option[selected]')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['run', 'card'], self::options($form, 'unit'));

        $row = $crawler->filter('[data-metrics-chart] .lp-data-table__row');
        self::assertSame(['Time in tests', '4 s'], $row->filter('.lp-data-table__cell')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertStringContainsString('Time in tests', trim($crawler->filter('[data-metrics-title]')->text()));
    }

    public function test_a_numeric_bucket_name_selects_only_its_own_option(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-bucket-numeric@example.com');
        $project = $this->project($em, $owner, 'Bucket numeric');
        $this->seedBucketTimes($em, $this->seedRun($em, $project), ['1' => 10, '01' => 5]);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=bucket-time:1&unit=run&range=all');

        self::assertResponseIsSuccessful();
        self::assertSame(['bucket-time:1'], $crawler->filter('form[data-metrics-form] select[name="metric"] option[selected]')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
    }

    public function test_a_valid_bucket_with_no_time_shows_zero_on_the_runs_with_data(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-bucket-empty@example.com');
        $project = $this->project($em, $owner, 'Bucket empty');
        $this->seedBucketTimes($em, $this->seedRun($em, $project, cardNumber: 4, endedAt: new \DateTimeImmutable('2026-09-02 10:05:00')), ['git' => 700]);
        $this->seedRun($em, $project, cardNumber: 5, endedAt: new \DateTimeImmutable('2026-09-01 10:05:00'));
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=bucket-time:deploy&unit=run&statistic=count&range=all');

        self::assertResponseIsSuccessful();
        $group = $crawler->filter('form[data-metrics-form] select[name="metric"] optgroup')->eq(0);
        self::assertSame(['bucket-time:deploy', 'bucket-time:git'], $group->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('bucket-time:deploy', $group->filter('option[selected]')->attr('value'));

        $row = $crawler->filter('[data-metrics-chart] .lp-data-table__row');
        self::assertSame(['Time in deploy', '1'], $row->filter('.lp-data-table__cell')->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-theirs@example.com');
        $stranger = $this->user($em, 'metrics-stranger@example.com');
        $projectId = (string) $this->project($em, $owner, 'Their metrics')->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics');

        self::assertResponseStatusCodeSame(403);
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

    /** @return list<string> */
    private static function options(Crawler $form, string $name): array
    {
        return $form->filter('select[name="'.$name.'"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
    }
}
