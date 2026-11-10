<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowMetricsControllerFormulaTest extends WebTestCase
{
    use InsightsScenario;

    public function test_a_one_metric_graph_offers_add_formula_and_keeps_the_formula_panel_closed(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('formula-closed');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics');

        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('[data-metric-formula-target="addFormula"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('[data-metric-formula-target="panel"]')->attr('hidden'));
        self::assertSame('false', $crawler->filter('form[data-metrics-form]')->attr('data-metric-formula-open-value'));
        self::assertSame('symfony--ux-autocomplete--autocomplete', $crawler->filter('#metrics-metric')->attr('data-controller'));
        $joinable = json_decode((string) $crawler->filter('form[data-metrics-form]')->attr('data-metric-formula-joinable-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('cache-read-tokens', $joinable);
        self::assertSame(['sum'], array_values(array_intersect(['sum'], $joinable['cache-read-tokens']['statistics'])));
        self::assertArrayNotHasKey('stop-rate', $joinable);
        self::assertArrayNotHasKey('cold-wake-share', $joinable);
    }

    public function test_a_formula_page_draws_one_row_for_each_letter_and_lists_only_shared_units(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('formula-rows');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?rows=A:cache-read-tokens:sum,B:cache-write-tokens:sum&formula='.rawurlencode('A / B'));

        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('[data-metric-formula-target="panel"]')->attr('hidden'));
        self::assertSame(['A', 'B'], $crawler->filter('.lp-metric-formula__rows [data-metric-formula-role="letter"]')->each(static fn ($letter): string => trim($letter->text())));
        self::assertSame('cache-read-tokens', $crawler->filter('.lp-metric-formula__rows select[data-metric-formula-role="metric"] option[selected]')->first()->attr('value'));
        self::assertNotNull($crawler->filter('[data-metric-formula-target="single"]')->first()->attr('hidden'));
        self::assertNotNull($crawler->filter('#metrics-metric')->attr('disabled'));
        self::assertSame('A / B', $crawler->filter('#metrics-formula')->attr('value'));
    }

    public function test_the_formula_check_route_reports_a_problem_with_its_place_and_a_good_formula(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('formula-check');
        $projectId = (string) $project->id;
        $this->em()->clear();
        $client->loginUser($project->owner);
        $path = '/projects/'.$projectId.'/analytics/metrics/formula-check';

        $client->request(Request::METHOD_GET, $path, ['rows' => 'A:cache-read-tokens:sum,B:cache-write-tokens:sum', 'formula' => 'A / (B', 'format' => 'auto']);
        self::assertResponseIsSuccessful();
        $bad = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertFalse($bad['ok']);
        self::assertStringContainsString('parenthesis', (string) $bad['error']);
        self::assertStringContainsString('At character', (string) $bad['error']);

        $client->request(Request::METHOD_GET, $path, ['rows' => 'A:cache-read-tokens:sum,B:cache-write-tokens:sum', 'formula' => 'A / B', 'format' => 'auto']);
        self::assertSame(['ok' => true, 'error' => null], json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));

        $client->request(Request::METHOD_GET, $path, ['rows' => 'A:cache-read-tokens:sum', 'formula' => 'A / B', 'format' => 'auto']);
        $missing = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('B', (string) $missing['error']);
    }

    public function test_a_user_outside_the_project_cannot_use_the_formula_check(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('formula-check-denied');
        $other = $this->scenarioProject('formula-check-other');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($other->owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics/formula-check', ['rows' => 'A:cost:sum', 'formula' => 'A']);

        self::assertResponseStatusCodeSame(403);
    }
}
