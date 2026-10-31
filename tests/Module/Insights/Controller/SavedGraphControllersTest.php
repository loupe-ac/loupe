<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\View\MetricChartType;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

final class SavedGraphControllersTest extends WebTestCase
{
    use InsightsScenario;

    private const array HEADERS = ['HTTP_REFERER' => 'http://localhost/'];

    private const array SETTINGS = [
        'metric' => 'cost',
        'unit' => 'run',
        'statistic' => 'sum',
        'group' => 'none',
        'range' => 'thirty-days',
        'bucket' => 'day',
        'compare' => 'off',
        'chart' => 'bars',
    ];

    public function test_a_save_stores_the_graph_and_returns_to_the_metrics_with_its_settings(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('save-ok');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->savePath($projectId), $this->form(['name' => 'My cost']), [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/metrics?unit=run&statistic=sum&range=thirty-days&bucket=day&chart=bars');
        self::assertSame(['success' => ['The graph is saved.']], $this->flashes($client));
        $graphs = $this->stored($projectId);
        self::assertCount(1, $graphs);
        self::assertSame('My cost', $graphs[0]->name);
        self::assertSame(MetricChartType::Bars, $graphs[0]->chartType);
        self::assertSame(0, $graphs[0]->position);
    }

    public function test_a_save_with_no_name_takes_the_title(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('save-title');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->savePath($projectId), $this->form(['name' => '']), [], self::HEADERS);

        self::assertResponseRedirects();
        self::assertSame('Cost per run, sum, last 30 days, per day', $this->stored($projectId)[0]->name);
    }

    public function test_a_wrong_csrf_token_stores_nothing_and_flashes_an_error(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('save-csrf');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->savePath($projectId), $this->form(['_token' => 'wrong']), [], self::HEADERS);

        self::assertResponseRedirects();
        self::assertSame(['error' => ['Loupe could not save the graph. Try again.']], $this->flashes($client));
        self::assertSame([], $this->stored($projectId));
    }

    public function test_a_name_over_120_characters_stores_nothing(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('save-long');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->savePath($projectId), $this->form(['name' => str_repeat('a', 121)]), [], self::HEADERS);

        self::assertResponseRedirects();
        self::assertArrayHasKey('error', $this->flashes($client));
        self::assertSame([], $this->stored($projectId));
    }

    public function test_a_locked_chart_type_is_refused_and_kept_in_the_redirect(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('save-locked');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->savePath($projectId), $this->form(['chart' => 'donut', 'statistic' => 'median']), [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/metrics?metric=cost&unit=run&statistic=median&group=none&range=thirty-days&bucket=day&compare=off&chart=donut');
        self::assertSame(['error' => ['This chart type needs a sum, a count or a mean of a metric that adds up. Change the statistic, or pick another chart type.']], $this->flashes($client));
        self::assertSame([], $this->stored($projectId));
    }

    public function test_a_project_at_the_cap_shows_the_cap_message(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('save-cap');
        for ($i = 0; $i < 12; ++$i) {
            $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->savePath($projectId), $this->form(), [], self::HEADERS);

        self::assertResponseRedirects();
        self::assertSame(['error' => ['The dashboard holds at most 12 graphs. Remove a graph first.']], $this->flashes($client));
        self::assertCount(12, $this->stored($projectId));
    }

    public function test_an_update_changes_the_graph_and_returns_to_the_metrics_with_its_id(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('update-ok');
        $graph = $this->seedSavedGraph($project, 'Tests', 3);
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->updatePath($projectId, $graphId), $this->form(['name' => 'Renamed', 'chart' => 'line']), [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/metrics?graph='.$graphId.'&unit=run&statistic=sum&range=thirty-days&bucket=day');
        self::assertSame(['success' => ['The graph is updated.']], $this->flashes($client));
        $graphs = $this->stored($projectId);
        self::assertCount(1, $graphs);
        self::assertSame('Renamed', $graphs[0]->name);
        self::assertSame(3, $graphs[0]->position);
    }

    public function test_an_update_with_a_wrong_csrf_token_changes_nothing(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('update-csrf');
        $graph = $this->seedSavedGraph($project, 'Tests', 0);
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->updatePath($projectId, $graphId), $this->form(['name' => 'Renamed', '_token' => 'wrong']), [], self::HEADERS);

        self::assertResponseRedirects();
        self::assertArrayHasKey('error', $this->flashes($client));
        self::assertSame('Tests', $this->stored($projectId)[0]->name);
    }

    public function test_a_graph_of_another_project_cannot_be_updated(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('update-mine');
        $theirs = $this->seedSavedGraph($this->scenarioProject('update-theirs'), 'Theirs', 0);
        $projectId = (string) $project->id;
        $theirsId = (string) $theirs->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->updatePath($projectId, $theirsId), $this->form(['name' => 'Mine now']), [], self::HEADERS);

        self::assertResponseStatusCodeSame(404);
        $this->em()->clear();
        self::assertSame('Theirs', $this->em()->find(InsightsSavedGraph::class, $theirsId)?->name);
    }

    public function test_a_removed_graph_cannot_be_updated(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('update-removed');
        $graph = $this->seedSavedGraph($project, 'Gone', 0, new \DateTimeImmutable('2026-10-01 10:00:00'));
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->updatePath($projectId, $graphId), $this->form(['name' => 'Back']), [], self::HEADERS);

        self::assertResponseStatusCodeSame(404);
        $this->em()->clear();
        self::assertSame('Gone', $this->em()->find(InsightsSavedGraph::class, $graphId)?->name);
    }

    /** @return iterable<string, array{bool}> */
    public static function writes(): iterable
    {
        yield 'save' => [false];
        yield 'update' => [true];
    }

    #[DataProvider('writes')]
    public function test_another_users_project_is_refused_as_the_metrics_tab_is(bool $update): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('graphs-theirs-'.uniqid());
        $graph = $this->seedSavedGraph($project, 'Theirs', 0);
        $stranger = $this->user($this->em(), 'graphs-stranger-'.uniqid().'@example.com');
        $projectId = (string) $project->id;
        $path = $update ? $this->updatePath($projectId, (string) $graph->id) : $this->savePath($projectId);
        $this->em()->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, $path, $this->form(['name' => 'Hijack']), [], self::HEADERS);

        self::assertResponseStatusCodeSame(403);
        $graphs = $this->stored($projectId);
        self::assertCount(1, $graphs);
        self::assertSame('Theirs', $graphs[0]->name);
    }

    public function test_a_get_is_not_allowed(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('graphs-get');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, $this->savePath($projectId));

        self::assertResponseStatusCodeSame(405);
    }

    private function savePath(string $projectId): string
    {
        return '/projects/'.$projectId.'/analytics/metrics/graphs';
    }

    private function updatePath(string $projectId, string $graphId): string
    {
        return $this->savePath($projectId).'/'.$graphId;
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array{saved_graph_form: array<string, string>}
     */
    private function form(array $overrides = []): array
    {
        return ['saved_graph_form' => $overrides + self::SETTINGS + ['name' => '', '_token' => 'csrf-token']];
    }

    /** @return array<string, list<string>> */
    private function flashes(KernelBrowser $client): array
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return $session->getFlashBag()->peekAll();
    }

    /** @return list<InsightsSavedGraph> */
    private function stored(string $projectId): array
    {
        $this->em()->clear();
        $project = $this->em()->find(Project::class, $projectId) ?? throw new \LogicException('The project exists.');
        $repository = static::getContainer()->get(InsightsSavedGraphRepository::class);
        self::assertInstanceOf(InsightsSavedGraphRepository::class, $repository);

        return $repository->findActive($project);
    }
}
