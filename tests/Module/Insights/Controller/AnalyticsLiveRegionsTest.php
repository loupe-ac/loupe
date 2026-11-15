<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisScope;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\ExperimentScenario;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/** Each Analytics page declares its live regions and subscribes to the analytics topic. */
final class AnalyticsLiveRegionsTest extends WebTestCase
{
    use ExperimentScenario;
    use InsightsScenario;

    public function test_the_experiments_list_is_a_frame_of_the_experiments_area(): void
    {
        [$client, $project] = $this->signedIn('live-experiments');
        $this->experimentRun($this->em(), $project, $this->experimentCard($this->em(), $project, 1), 'a');

        $crawler = $this->visit($client, $project, '/experiments');

        self::assertResponseIsSuccessful();
        $frame = $this->frame($crawler, 'analytics-experiments');
        self::assertSame('["analytics.changed"]', $frame->attr('data-live-region-types-value'));
        self::assertSame('{"analytics.changed":{"area":"experiments"}}', $frame->attr('data-live-region-match-type-value'));
        self::assertSame($this->base($project).'/experiments', $frame->attr('data-live-region-url-value'));
        self::assertCount(1, $frame->filter('[data-experiment]'));
        self::assertTrue($this->subscribes($crawler, $project));
    }

    public function test_the_experiments_list_keeps_the_archived_switch_in_the_frame_address(): void
    {
        [$client, $project] = $this->signedIn('live-experiments-archived');

        $crawler = $this->visit($client, $project, '/experiments?archived=1');

        self::assertSame($this->base($project).'/experiments?archived=1', $this->frame($crawler, 'analytics-experiments')->attr('data-live-region-url-value'));
    }

    public function test_the_experiment_page_refreshes_on_its_area_and_on_worker_runs(): void
    {
        [$client, $project] = $this->signedIn('live-experiment');
        $this->experimentRun($this->em(), $project, $this->experimentCard($this->em(), $project, 1), 'a');

        $crawler = $this->visit($client, $project, '/experiments/model-test');

        self::assertResponseIsSuccessful();
        $frame = $this->frame($crawler, 'analytics-experiment');
        self::assertSame('["analytics.changed","worker_run.changed"]', $frame->attr('data-live-region-types-value'));
        self::assertSame('{"analytics.changed":{"area":"experiments"}}', $frame->attr('data-live-region-match-type-value'));
        self::assertSame($this->base($project).'/experiments/model-test', $frame->attr('data-live-region-url-value'));
        self::assertCount(1, $frame->filter('[data-experiment-left-out]'));
        $heading = $this->frame($crawler, 'analytics-experiment-heading');
        self::assertSame($this->base($project).'/experiments/model-test', $heading->attr('data-live-region-url-value'));
        self::assertCount(1, $heading->filter('.lp-experiment-heading'));
        self::assertTrue($this->subscribes($crawler, $project));
    }

    public function test_the_first_page_of_the_cards_is_a_frame(): void
    {
        [$client, $project] = $this->signedIn('live-cards');
        $card = $this->experimentCard($this->em(), $project, 1);
        $this->experimentRun($this->em(), $project, $card, 'a');

        $crawler = $this->visit($client, $project, '/experiments/model-test/cards?variant=a');

        self::assertResponseIsSuccessful();
        $frame = $this->frame($crawler, 'analytics-experiment-cards');
        self::assertSame('frame', $frame->attr('data-live-region-mode-value'));
        self::assertSame($this->base($project).'/experiments/model-test/cards?variant=a', $frame->attr('data-live-region-url-value'));
        self::assertSame((string) $card->id, $frame->filter('[data-live-row]')->attr('data-live-row'));
        self::assertCount(0, $crawler->filter('[data-live-since]'));
        self::assertSame($this->base($project).'/experiments/model-test/cards?variant=a', $this->frame($crawler, 'analytics-experiment-heading')->attr('data-live-region-url-value'));
        self::assertTrue($this->subscribes($crawler, $project));
    }

    public function test_a_later_page_of_the_cards_reads_its_rows_by_id(): void
    {
        [$client, $project] = $this->signedIn('live-cards-later');
        $em = $this->em();
        $ids = [];
        foreach (range(1, 21) as $number) {
            $card = $this->experimentCard($em, $project, $number);
            $this->experimentRun($em, $project, $card, 'a', at: '-'.(30 + $number).' minutes');
            $ids[] = (string) $card->id;
        }

        $crawler = $this->visit($client, $project, '/experiments/model-test/cards?page=2');

        self::assertResponseIsSuccessful();
        $region = $crawler->filter('[data-live-region-mode-value="rows"]');
        self::assertCount(1, $region);
        self::assertSame($this->base($project).'/experiments/model-test/cards/rows', $region->attr('data-live-region-url-value'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/', (string) $region->attr('data-live-since'));
        self::assertSame([$ids[20]], $region->filter('[data-live-row]')->each(static fn (Crawler $row): ?string => $row->attr('data-live-row')));
        self::assertCount(0, $crawler->filter('turbo-frame#analytics-experiment-cards'));
        self::assertCount(1, $region->filter('#experiment-cards-newer'));
        self::assertSame($this->base($project).'/experiments/model-test/cards?page=2', $this->frame($crawler, 'analytics-experiment-heading')->attr('data-live-region-url-value'));
    }

    public function test_the_first_page_of_the_reports_is_a_frame_and_the_settings_form_is_outside_it(): void
    {
        [$client, $project] = $this->signedIn('live-reports');
        $this->seedAnalysis($this->em(), $project);

        $crawler = $this->visit($client, $project, '/reports');

        self::assertResponseIsSuccessful();
        $frame = $this->frame($crawler, 'analytics-reports');
        self::assertSame('{"analytics.changed":{"area":"reports"}}', $frame->attr('data-live-region-match-type-value'));
        self::assertSame($this->base($project).'/reports', $frame->attr('data-live-region-url-value'));
        self::assertCount(1, $frame->filter('[data-analysis]'));
        self::assertCount(0, $frame->filter('[data-analytics-settings-form]'));
        self::assertCount(0, $frame->filter('[data-start-analysis-form]'));
        self::assertTrue($this->subscribes($crawler, $project));
    }

    public function test_a_later_page_of_the_reports_reads_its_rows_by_id(): void
    {
        [$client, $project] = $this->signedIn('live-reports-later');
        $em = $this->em();
        foreach (range(1, 51) as $offset) {
            $em->persist(new Analysis($project, AnalysisTopic::Cost, new AnalysisScope(MetricRange::NinetyDays), null, 'sonnet', 'medium', new \DateTimeImmutable('2026-10-01 09:00:00 +'.$offset.' minutes')));
        }
        $em->flush();

        $crawler = $this->visit($client, $project, '/reports?page=2');

        self::assertResponseIsSuccessful();
        $region = $crawler->filter('[data-live-region-mode-value="rows"]');
        self::assertCount(1, $region);
        self::assertSame($this->base($project).'/reports/rows?page=2', $region->attr('data-live-region-url-value'));
        self::assertCount(1, $region->filter('[data-live-row]'));
        self::assertNotSame('', (string) $region->attr('data-live-since'));
        self::assertCount(0, $crawler->filter('turbo-frame#analytics-reports'));
    }

    public function test_the_settings_form_has_a_band_that_names_the_settings_signal(): void
    {
        [$client, $project] = $this->signedIn('live-settings');

        $crawler = $this->visit($client, $project, '/reports');

        $region = $crawler->filter('[data-analytics-settings-region]');
        self::assertCount(1, $region);
        self::assertSame('band', $region->attr('data-live-region-mode-value'));
        self::assertSame('{"analytics.changed":{"area":"reports","id":"settings"}}', $region->attr('data-live-region-match-type-value'));
        self::assertSame($this->base($project).'/reports/settings/fingerprint', $region->attr('data-live-region-url-value'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $region->attr('data-live-fingerprint'));
        self::assertCount(1, $region->filter('[data-analytics-settings-form]'));
        $band = $region->filter('[data-analytics-settings-band]');
        self::assertCount(1, $band);
        self::assertNotNull($band->attr('hidden'));
        self::assertSame('band', $band->attr('data-live-region-target'));
        $link = $band->filter('a');
        self::assertSame('_blank', $link->attr('target'));
        self::assertSame('noopener', $link->attr('rel'));
        self::assertSame($this->base($project).'/reports', $link->attr('href'));
    }

    public function test_the_metrics_result_is_a_live_frame_that_carries_the_query(): void
    {
        [$client, $project] = $this->signedIn('live-metrics');

        $crawler = $this->visit($client, $project, '/metrics?unit=run&statistic=sum');

        self::assertResponseIsSuccessful();
        $frame = $this->frame($crawler, 'metrics-live');
        self::assertSame('["worker_run.changed","board.card_changed","analytics.changed"]', $frame->attr('data-live-region-types-value'));
        self::assertSame('{"analytics.changed":{"area":"bucket_rules"}}', $frame->attr('data-live-region-match-type-value'));
        self::assertSame($this->base($project).'/metrics?unit=run&statistic=sum', $frame->attr('data-live-region-url-value'));
        self::assertSame('5000', $frame->attr('data-live-region-quiet-value'));
        self::assertSame('30000', $frame->attr('data-live-region-max-value'));
        self::assertCount(1, $crawler->filter('turbo-frame#metrics-result turbo-frame#metrics-live'));
        self::assertCount(0, $crawler->filter('form[data-metrics-form] turbo-frame'));
        self::assertTrue($this->subscribes($crawler, $project));
        self::assertTrue($this->subscribes($crawler, $project, 'board'));
    }

    public function test_a_live_read_of_the_metrics_brings_no_option_streams(): void
    {
        [$client, $project] = $this->signedIn('live-metrics-read');

        $crawler = $this->visit($client, $project, '/metrics', ['HTTP_TURBO_FRAME' => 'metrics-live', 'HTTP_X_LOUPE_LIVE_REFRESH' => '1']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->frame($crawler, 'metrics-live'));
        self::assertCount(0, $crawler->filter('turbo-stream'));
    }

    public function test_the_bucket_rules_are_a_frame_and_the_add_form_is_outside_it(): void
    {
        [$client, $project] = $this->signedIn('live-bucket-rules');

        $crawler = $this->visit($client, $project, '/time-buckets?range=thirty-days');

        self::assertResponseIsSuccessful();
        $frame = $this->frame($crawler, 'analytics-bucket-rules');
        self::assertSame('{"analytics.changed":{"area":"bucket_rules"}}', $frame->attr('data-live-region-match-type-value'));
        self::assertSame($this->base($project).'/time-buckets?range=thirty-days', $frame->attr('data-live-region-url-value'));
        self::assertCount(0, $frame->filter('[data-bucket-rule-form]'));
        self::assertCount(1, $crawler->filter('[data-bucket-rule-form]'));
        self::assertCount(1, $frame->filter('[data-bucket-summary]'));
        self::assertTrue($this->subscribes($crawler, $project));
    }

    /** @return array{KernelBrowser, Project} */
    private function signedIn(string $name): array
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, $name.'@example.com'));

        return [$client, $project];
    }

    /** @param array<string, string> $server */
    private function visit(KernelBrowser $client, Project $project, string $path, array $server = []): Crawler
    {
        $this->em()->clear();
        $client->loginUser($project->owner);

        return $client->request(Request::METHOD_GET, $this->base($project).$path, server: $server);
    }

    private function base(Project $project): string
    {
        return '/projects/'.$project->id.'/analytics';
    }

    private function frame(Crawler $crawler, string $id): Crawler
    {
        $frame = $crawler->filter('turbo-frame#'.$id);
        self::assertCount(1, $frame);
        self::assertSame('live-region', $frame->attr('data-controller'));
        self::assertSame('_top', $frame->attr('target'));
        self::assertNotNull($frame->attr('data-live-content'));

        return $frame;
    }

    private function subscribes(Crawler $crawler, Project $project, string $topic = 'analytics'): bool
    {
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $expected = 'board' === $topic ? $topics->forBoard($project->requireId()) : $topics->forAnalytics($project->requireId());

        return \in_array($expected, $crawler->filter('input[data-mercure-topic]')->each(static fn (Crawler $input): ?string => $input->attr('value')), true);
    }
}
