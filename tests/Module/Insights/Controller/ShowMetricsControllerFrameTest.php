<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/** A control change on the Metrics tab reloads the result frame alone, and the frame answer corrects the dependent fields. */
final class ShowMetricsControllerFrameTest extends WebTestCase
{
    use InsightsScenario;

    public function test_the_page_sends_the_form_to_the_result_frame_and_holds_no_stream(): void
    {
        $client = static::createClient();
        $project = $this->projectWithRun('frame-page');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $this->path($projectId).'?unit=run&statistic=sum&range=all&bucket=day');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-metrics-form]');
        self::assertSame('metrics-result', $form->attr('data-turbo-frame'));
        self::assertSame('replace', $form->attr('data-turbo-action'));
        $frame = $crawler->filter('turbo-frame#metrics-result');
        self::assertCount(1, $frame);
        self::assertCount(1, $frame->filter('[data-metrics-chart]'));
        self::assertSame('_top', $frame->filter('form[data-save-graph]')->attr('data-turbo-frame'));
        self::assertCount(0, $crawler->filter('turbo-stream'));
        self::assertContains('Turbo-Frame', $client->getResponse()->getVary());
    }

    public function test_a_frame_request_updates_the_fields_that_depend_on_the_metric_and_the_range(): void
    {
        $client = static::createClient();
        $project = $this->projectWithRun('frame-single');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            $this->path($projectId).'?metric=stop-rate&unit=card&statistic=p90&group=model&range=all&bucket=day&compare=previous',
            server: ['HTTP_TURBO_FRAME' => 'metrics-result'],
        );

        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('turbo-frame#metrics-result');
        self::assertSame(
            ['metrics-statistic', 'metrics-unit', 'metrics-group', 'metrics-bucket', 'metrics-compare', 'metrics-chart'],
            $frame->filter('turbo-stream')->each(static fn (Crawler $stream): string => (string) $stream->attr('target')),
        );
        self::assertSame(['update'], array_values(array_unique($frame->filter('turbo-stream')->each(static fn (Crawler $stream): string => (string) $stream->attr('action')))));
        self::assertSame([['mean', 'count'], 'mean'], self::streamOptions($frame, 'metrics-statistic'));
        self::assertSame([['run'], 'run'], self::streamOptions($frame, 'metrics-unit'));
        self::assertCount(1, $frame->filter('turbo-stream[target="metrics-compare"] option[value="previous"][disabled]'));
    }

    public function test_a_formula_frame_request_leaves_the_fields_the_formula_rows_narrow_to_the_page(): void
    {
        $client = static::createClient();
        $project = $this->projectWithRun('frame-formula');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            $this->path($projectId).'?unit=run&range=all&bucket=month&rows=A:cost:sum,B:cost:count&formula=A / B',
            server: ['HTTP_TURBO_FRAME' => 'metrics-result'],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['metrics-bucket', 'metrics-compare', 'metrics-chart'],
            $crawler->filter('turbo-frame#metrics-result turbo-stream')->each(static fn (Crawler $stream): string => (string) $stream->attr('target')),
        );
    }

    public function test_the_unsaved_changes_guard_sits_in_the_frame_so_each_answer_resets_it(): void
    {
        $client = static::createClient();
        $project = $this->projectWithRun('frame-guard');
        $graph = $this->seedSavedGraph($project, 'Tests');
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $this->path($projectId).'?graph='.$graphId.'&statistic=mean', server: ['HTTP_TURBO_FRAME' => 'metrics-result']);

        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('turbo-frame#metrics-result [data-leave-guard-dirty-value]')->attr('data-leave-guard-dirty-value'));
    }

    /** @return array{list<string>, string} */
    private static function streamOptions(Crawler $frame, string $target): array
    {
        $options = $frame->filter('turbo-stream[target="'.$target.'"] option');

        return [
            $options->each(static fn (Crawler $option): string => (string) $option->attr('value')),
            (string) $options->filter('[selected]')->attr('value'),
        ];
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
}
