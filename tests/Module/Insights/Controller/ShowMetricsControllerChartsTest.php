<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ShowMetricsControllerChartsTest extends WebTestCase
{
    use BridgeScenario;

    public function test_compare_draws_the_previous_period_and_a_change_figure_with_no_sign_on_a_sum(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-compare@example.com');
        $project = $this->project($em, $owner, 'Compare');
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days')), costUsd: '3.00');
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 2, endedAt: new \DateTimeImmutable('-40 days')), costUsd: '2.00');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&statistic=sum&range=thirty-days&bucket=day&compare=previous');

        self::assertResponseIsSuccessful();
        self::assertSame('previous', $crawler->filter('select[name="compare"] option[selected]')->attr('value'));
        self::assertSame('Cost per run, sum, last 30 days vs the 30 days before, per day', trim($crawler->filter('[data-metrics-title]')->text()));
        $chart = $crawler->filter('[data-metrics-chart]');
        self::assertCount(1, $chart->filter('.lp-chart__line--previous'));
        self::assertStringContainsString('Previous period', $chart->filter('.lp-chart__legend')->text());
        self::assertCount(1, $chart->filter('.lp-chart__change'));
        self::assertCount(0, $chart->filter('.lp-chart__sign'));
        self::assertCount(0, $crawler->filter('[data-metrics-note="previous-in-table"]'));
    }

    public function test_the_sign_reads_not_enough_cards_on_a_thin_median(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-sign@example.com');
        $project = $this->project($em, $owner, 'Sign');
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days')), costUsd: '3.00');
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 2, endedAt: new \DateTimeImmutable('-40 days')), costUsd: '2.00');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&range=thirty-days&bucket=day&compare=previous');

        self::assertResponseIsSuccessful();
        self::assertSame('Not enough cards', trim($crawler->filter('[data-metrics-chart] .lp-chart__sign--not-enough-data')->text()));
    }

    public function test_a_locked_chart_type_falls_back_to_bars_with_a_note(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-locked@example.com');
        $project = $this->project($em, $owner, 'Locked');
        $this->seedRun($em, $project, workKind: 'plan');
        $this->seedRun($em, $project, workKind: 'review');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&metric=duration&group=stage&range=all&chart=stacked-bars');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-metrics-chart] figure.lp-chart--bars'));
        self::assertCount(0, $crawler->filter('figure.lp-chart--stacked-bars'));
        $note = $crawler->filter('[data-metrics-note="switched"]');
        self::assertCount(1, $note);
        self::assertStringStartsWith('Switched to bars:', trim($note->text()));
        self::assertCount(0, $crawler->filter('[data-metrics-note="locked"]'));
        self::assertSame('bars', $crawler->filter('select[name="chart"] option[selected]')->attr('value'));
        self::assertSame(['stacked-bars', 'percent-stacked', 'donut'], $crawler->filter('select[name="chart"] option[disabled]')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
    }

    public function test_the_default_line_chart_explains_which_types_are_locked(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-hint@example.com');
        $project = $this->project($em, $owner, 'Hint');
        $this->seedRun($em, $project);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&range=all');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('figure.lp-chart--line'));
        self::assertCount(1, $crawler->filter('[data-metrics-note="locked"]'));
        self::assertCount(0, $crawler->filter('[data-metrics-note="switched"]'));
        self::assertSame(['stacked-bars', 'percent-stacked', 'donut'], $crawler->filter('select[name="chart"] option[disabled]')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
    }

    public function test_a_sum_by_group_unlocks_the_stacked_types(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-stacked@example.com');
        $project = $this->project($em, $owner, 'Stacked');
        $this->seedRun($em, $project, workKind: 'plan');
        $this->seedRun($em, $project, workKind: 'review');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&metric=duration&statistic=sum&group=stage&range=all&chart=percent-stacked');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('figure.lp-chart--percent-stacked'));
        self::assertCount(0, $crawler->filter('select[name="chart"] option[disabled]'));
        self::assertCount(0, $crawler->filter('[data-metrics-note]'));
        self::assertSame('100% stacked', trim($crawler->filter('select[name="chart"] option[selected]')->text()));
    }

    public function test_a_grouped_bucket_metric_draws_one_line_per_bucket_and_has_no_group(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-each@example.com');
        $project = $this->project($em, $owner, 'Each');
        $this->seedBucketTimes($em, $this->seedRun($em, $project), ['tests' => 4000, 'git' => 1000]);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=bucket-time-each&unit=run&statistic=mean&range=all&bucket=day&chart=donut');

        self::assertResponseIsSuccessful();
        self::assertSame('bucket-time-each', $crawler->filter('select[name="metric"] option[selected]')->attr('value'));
        self::assertSame(['none'], $crawler->filter('select[name="group"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('Time in each bucket', trim($crawler->filter('select[name="metric"] option[value="bucket-time-each"]')->text()));
        self::assertCount(1, $crawler->filter('figure.lp-chart--donut'));
        self::assertSame(['git', 'tests'], $crawler->filter('[data-metrics-chart] .lp-chart__legend li')->each(static fn (Crawler $item): string => trim($item->text())));
        $shares = $crawler->filter('[data-metrics-chart] .lp-data-table__row')->each(static fn (Crawler $row): string => trim($row->filter('.lp-data-table__cell')->eq(2)->text()));
        self::assertSame(['20%', '80%'], $shares);
        self::assertCount(0, $crawler->filter('[data-metrics-note="switched"]'));
    }

    public function test_all_time_cannot_compare(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-all-time@example.com');
        $project = $this->project($em, $owner, 'All time');
        $this->seedRun($em, $project);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&range=all&compare=previous');

        self::assertResponseIsSuccessful();
        self::assertSame('off', $crawler->filter('select[name="compare"] option[selected]')->attr('value'));
        self::assertCount(1, $crawler->filter('select[name="compare"] option[value="previous"][disabled]'));
        self::assertCount(0, $crawler->filter('.lp-chart__line--previous'));
    }

    public function test_seven_days_disable_the_buckets_it_refuses(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-seven@example.com');
        $projectId = (string) $this->project($em, $owner, 'Seven')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?range=seven-days&bucket=week');

        self::assertResponseIsSuccessful();
        self::assertSame(['day', 'week', 'month'], $crawler->filter('select[name="bucket"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['week', 'month'], $crawler->filter('select[name="bucket"] option[disabled]')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('day', $crawler->filter('select[name="bucket"] option[selected]')->attr('value'));
    }

    public function test_bars_with_a_compare_say_that_the_table_holds_the_previous_period(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-bars-note@example.com');
        $project = $this->project($em, $owner, 'Bars note');
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days')), costUsd: '3.00');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&range=thirty-days&bucket=day&compare=previous&chart=bars');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-metrics-note="previous-in-table"]'));
        self::assertCount(1, $crawler->filter('figure.lp-chart--bars'));
    }

    public function test_the_number_chart_has_no_plot_and_no_table(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-number@example.com');
        $project = $this->project($em, $owner, 'Number');
        $this->seedUsage($em, $this->seedRun($em, $project, cardNumber: 1, endedAt: new \DateTimeImmutable('-5 days')), costUsd: '3.00');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&statistic=sum&range=thirty-days&chart=number');

        self::assertResponseIsSuccessful();
        self::assertSame('$3.00', trim($crawler->filter('[data-metrics-chart] .lp-chart__figure-value')->text()));
        self::assertCount(0, $crawler->filter('[data-metrics-chart] svg'));
        self::assertCount(0, $crawler->filter('[data-metrics-chart] .lp-data-table'));
    }

    public function test_a_locked_type_on_a_project_with_no_rows_shows_the_empty_state_and_no_note(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'charts-empty@example.com');
        $projectId = (string) $this->project($em, $owner, 'Empty charts')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?chart=donut&compare=previous');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-metrics-empty]'));
        self::assertCount(0, $crawler->filter('[data-metrics-note]'));
        self::assertCount(0, $crawler->filter('[data-metrics-chart]'));
    }
}
