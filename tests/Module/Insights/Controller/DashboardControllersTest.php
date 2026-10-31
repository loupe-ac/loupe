<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

final class DashboardControllersTest extends WebTestCase
{
    use InsightsScenario;

    private const array HEADERS = ['HTTP_REFERER' => 'http://localhost/'];

    private const string BASE = '/analytics/dashboard';

    public function test_the_analytics_link_opens_the_dashboard_when_a_graph_is_on_show(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('landing-graph');
        $this->seedSavedGraph($project, 'Tests', 0);
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics');

        self::assertResponseRedirects('/projects/'.$projectId.self::BASE);
    }

    public function test_the_analytics_link_opens_metrics_when_the_project_has_no_graph_or_only_removed_ones(): void
    {
        $client = static::createClient();
        $empty = $this->scenarioProject('landing-empty');
        $removedOnly = $this->scenarioProject('landing-removed');
        $this->seedSavedGraph($removedOnly, 'Gone', 0, new \DateTimeImmutable('-1 hour'));
        $paths = ['/projects/'.$empty->id.'/analytics' => $empty, '/projects/'.$removedOnly->id.'/analytics' => $removedOnly];
        $this->em()->clear();

        foreach ($paths as $path => $project) {
            $client->loginUser($project->owner);
            $client->request(Request::METHOD_GET, $path);
            self::assertResponseRedirects(str_replace('/analytics', '/analytics/metrics', $path));
        }
    }

    public function test_a_stranger_is_refused_by_every_read_route(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('read-theirs');
        $graph = $this->seedSavedGraph($project, 'Theirs', 0);
        $stranger = $this->user($this->em(), 'read-stranger-'.uniqid().'@example.com');
        $base = '/projects/'.$project->id;
        $paths = [$base.'/analytics', $base.self::BASE, $base.self::BASE.'/grid', $base.self::BASE.'/graphs/'.$graph->id];
        $this->em()->clear();

        $client->loginUser($stranger);
        foreach ($paths as $path) {
            $client->request(Request::METHOD_GET, $path);
            self::assertResponseStatusCodeSame(403, $path);
        }
    }

    public function test_the_dashboard_shows_a_lazy_frame_for_a_graph_with_no_kept_result_and_the_tile_once_it_is_kept(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('page-lazy');
        $graph = $this->seedSavedGraph($project, 'Cost trend', 0);
        $graph->metric = 'cost';
        $this->em()->flush();
        $base = '/projects/'.$project->id.self::BASE;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $base);

        self::assertResponseIsSuccessful();
        $waiting = $crawler->filter('[data-dashboard-tile="waiting"]');
        self::assertCount(1, $waiting);
        self::assertSame($base.'/graphs/'.$graphId, $waiting->attr('src'));
        self::assertSame('lazy', $waiting->attr('loading'));
        self::assertSame('dashboard-graph-'.$graphId, $waiting->attr('id'));

        $crawler = $client->request(Request::METHOD_GET, $base.'/graphs/'.$graphId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-dashboard-tile="ready"]'));

        $crawler = $client->request(Request::METHOD_GET, $base);
        self::assertCount(0, $crawler->filter('[data-dashboard-tile="waiting"]'));
        self::assertCount(1, $crawler->filter('[data-dashboard-tile="ready"]'));
    }

    public function test_the_forms_of_a_tile_post_what_the_controllers_read(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('page-forms');
        $first = $this->seedSavedGraph($project, 'First', 0);
        $first->metric = 'cost';
        $second = $this->seedSavedGraph($project, 'Second', 1);
        $second->metric = 'cost';
        $this->em()->flush();
        $projectId = (string) $project->id;
        $base = '/projects/'.$projectId.self::BASE;
        $firstId = (string) $first->id;
        $secondId = (string) $second->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        foreach ([$firstId, $secondId] as $id) {
            $client->request(Request::METHOD_GET, $base.'/graphs/'.$id);
        }
        $crawler = $client->request(Request::METHOD_GET, $base);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('turbo-frame[data-dashboard-tile="ready"][target="_top"]'));
        self::assertCount(1, $crawler->filter('[data-dashboard-add]'));
        self::assertSame($base.'/graphs/'.$firstId.'/remove', $crawler->filter('#dashboard-graph-'.$firstId.' form[data-dashboard-remove-form]')->attr('action'));
        self::assertSame('Open in Metrics', trim($crawler->filter('#dashboard-graph-'.$firstId.' .lp-menu__item[href]')->text()));
        self::assertStringContainsString('graph='.$firstId, (string) $crawler->filter('#dashboard-graph-'.$firstId.' .lp-menu__item[href]')->attr('href'));
        self::assertCount(0, $crawler->filter('#dashboard-graph-'.$firstId.' form[action$="/move/up"]'));
        self::assertCount(1, $crawler->filter('#dashboard-graph-'.$firstId.' form[action$="/move/down"]'));

        $tile = $crawler->filter('#dashboard-graph-'.$firstId);
        $client->submit($tile->filter('button[value="full"]')->form());
        self::assertResponseRedirects($base);
        $client->submit($tile->filter('button[name$="[show]"]')->form());
        self::assertResponseRedirects($base);
        $client->submit($tile->filter('form[data-graph-tile-target="renameForm"]')->form(['rename_graph_'.$firstId.'[name]' => 'Renamed']));
        self::assertResponseRedirects($base);
        $drag = $crawler->filter('#dashboard-graph-'.$firstId.' form[data-graph-drag-form]')->form(['drag_graph_'.$firstId.'[steps]' => '1']);
        $client->submit($drag);
        self::assertResponseRedirects($base);

        $this->em()->clear();
        $stored = static::getContainer()->get(InsightsSavedGraphRepository::class)->find($firstId);
        self::assertInstanceOf(InsightsSavedGraph::class, $stored);
        self::assertSame('Renamed', $stored->name);
        self::assertSame(SavedGraphWidth::Full, $stored->width);
        self::assertFalse($stored->showLegend);
        self::assertSame(['Second', 'Renamed'], $this->activeNames($projectId));
    }

    public function test_the_empty_dashboard_offers_three_starting_points_that_open_metrics(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('page-starts');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.self::BASE);

        $starts = $crawler->filter('[data-dashboard-start]');
        self::assertCount(3, $starts);
        foreach ($starts->each(static fn ($start): ?string => $start->attr('href')) as $href) {
            self::assertStringStartsWith('/projects/'.$projectId.'/analytics/metrics?', (string) $href);
        }
        $client->click($starts->first()->link());
        self::assertResponseIsSuccessful();
    }

    public function test_a_broken_graph_shows_what_is_gone_and_computes_nothing(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('page-broken');
        $graph = $this->seedSavedGraph($project, 'Tests', 0);
        $base = '/projects/'.$project->id.self::BASE;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $base);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-dashboard-tile="waiting"]'));
        self::assertSame('bucket', $crawler->filter('[data-dashboard-broken]')->attr('data-dashboard-broken'));

        $crawler = $client->request(Request::METHOD_GET, $base.'/graphs/'.$graphId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-dashboard-broken="bucket"]'));
    }

    public function test_the_dashboard_with_no_graph_shows_the_empty_state(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('page-empty');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.self::BASE);

        self::assertResponseIsSuccessful();
        $empty = $crawler->filter('[data-dashboard-empty]');
        self::assertCount(1, $empty);
        self::assertStringContainsString('No graphs on the Dashboard yet', $empty->text());
        self::assertStringNotContainsString('Dashboard yet.', $empty->text());
        self::assertStringContainsString('ready when you open Analytics.', $empty->text());
    }

    public function test_the_grid_is_a_turbo_stream_of_the_tiles_on_show(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('grid');
        $this->seedSavedGraph($project, 'Shown', 0);
        $this->seedSavedGraph($project, 'Removed', 1, new \DateTimeImmutable('-1 hour'));
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.self::BASE.'/grid');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="dashboard-grid">', $content);
        self::assertStringContainsString('Shown', $content);
        self::assertStringNotContainsString('Removed', $content);
    }

    public function test_the_graph_route_of_another_project_and_of_a_removed_graph_is_a_not_found(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('graph-404');
        $theirs = $this->seedSavedGraph($this->scenarioProject('graph-404-theirs'), 'Theirs', 0);
        $removed = $this->seedSavedGraph($project, 'Removed', 0, new \DateTimeImmutable('-1 hour'));
        $base = '/projects/'.$project->id.self::BASE.'/graphs/';
        $ids = [(string) $theirs->id, (string) $removed->id, 'not-a-uuid'];
        $this->em()->clear();

        $client->loginUser($project->owner);
        foreach ($ids as $id) {
            $client->request(Request::METHOD_GET, $base.$id);
            self::assertResponseStatusCodeSame(404, $id);
        }
    }

    public function test_the_undo_notice_shows_for_a_graph_that_is_removed_and_for_no_other(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('notice');
        $removed = $this->seedSavedGraph($project, 'Cost <b>trend</b>', 0, new \DateTimeImmutable('-1 hour'));
        $active = $this->seedSavedGraph($project, 'Active', 1);
        $theirs = $this->seedSavedGraph($this->scenarioProject('notice-theirs'), 'Theirs', 0, new \DateTimeImmutable('-1 hour'));
        $expired = $this->seedSavedGraph($project, 'Expired', 2, new \DateTimeImmutable('-25 hours'));
        $base = '/projects/'.$project->id.self::BASE;
        $ids = [(string) $removed->id, (string) $active->id, (string) $theirs->id, (string) $expired->id, 'junk'];
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $base.'?removed='.$ids[0]);
        $undo = $crawler->filter('[data-dashboard-undo]');
        self::assertCount(1, $undo);
        self::assertSame($base.'/graphs/'.$ids[0].'/undo', $undo->attr('action'));
        self::assertStringContainsString('Removed “Cost &lt;b&gt;trend&lt;/b&gt;” from the Dashboard.', (string) $client->getResponse()->getContent());

        foreach (array_slice($ids, 1) as $id) {
            $crawler = $client->request(Request::METHOD_GET, $base.'?removed='.$id);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[data-dashboard-undo]'), $id);
        }
    }

    public function test_a_remove_redirects_to_the_dashboard_with_the_id_and_the_undo_brings_the_graph_back(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('remove-undo');
        $first = $this->seedSavedGraph($project, 'First', 0);
        $second = $this->seedSavedGraph($project, 'Second', 1);
        $projectId = (string) $project->id;
        $path = '/projects/'.$projectId.self::BASE.'/graphs/'.$first->id;
        $firstId = (string) $first->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $path.'/remove', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertResponseRedirects('/projects/'.$projectId.self::BASE.'?removed='.$firstId);
        self::assertSame(['Second'], $this->activeNames($projectId));

        $client->request(Request::METHOD_POST, $path.'/undo', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertResponseRedirects('/projects/'.$projectId.self::BASE);
        self::assertSame(['First', 'Second'], $this->activeNames($projectId));
        self::assertNotSame('', (string) $second->id);
    }

    public function test_an_undo_at_the_cap_flashes_the_cap_message_and_keeps_the_notice(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('undo-cap');
        $removed = $this->seedSavedGraph($project, 'Removed', 0, new \DateTimeImmutable('-1 hour'));
        for ($i = 1; $i <= 12; ++$i) {
            $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }
        $projectId = (string) $project->id;
        $removedId = (string) $removed->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.self::BASE.'/graphs/'.$removedId.'/undo', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.self::BASE.'?removed='.$removedId);
        self::assertSame(['error' => ['The dashboard holds at most 12 graphs. Remove a graph first.']], $this->flashes($client));
        self::assertCount(12, $this->activeNames($projectId));
    }

    public function test_a_rename_a_resize_and_a_legend_switch_change_the_graph_and_return_to_the_dashboard(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('forms-ok');
        $graph = $this->seedSavedGraph($project, 'Old', 0);
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $base = '/projects/'.$projectId.self::BASE.'/graphs/'.$graphId;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $base.'/rename', ['rename_graph_'.$graphId => ['name' => 'New name', '_token' => 'csrf-token']], [], self::HEADERS);
        self::assertResponseRedirects('/projects/'.$projectId.self::BASE);
        $client->request(Request::METHOD_POST, $base.'/width', ['graph_width_'.$graphId => ['width' => 'full', '_token' => 'csrf-token']], [], self::HEADERS);
        self::assertResponseRedirects();
        $client->request(Request::METHOD_POST, $base.'/legend', ['graph_legend_'.$graphId => ['show' => 'off', '_token' => 'csrf-token']], [], self::HEADERS);
        self::assertResponseRedirects();

        $this->em()->clear();
        $stored = $this->em()->find(InsightsSavedGraph::class, $graphId) ?? throw new \LogicException();
        self::assertSame('New name', $stored->name);
        self::assertSame(SavedGraphWidth::Full, $stored->width);
        self::assertFalse($stored->showLegend);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function invalidForms(): iterable
    {
        yield 'a blank name' => ['rename', ['name' => '']];
        yield 'a name over 120 characters' => ['rename', ['name' => str_repeat('a', 121)]];
        yield 'an unknown width' => ['width', ['width' => 'huge']];
        yield 'an unknown legend choice' => ['legend', ['show' => 'maybe']];
        yield 'a wrong token' => ['rename', ['name' => 'Fine', '_token' => 'wrong']];
    }

    /** @param array<string, string> $fields */
    #[DataProvider('invalidForms')]
    public function test_an_invalid_form_changes_nothing_and_flashes_an_error(string $action, array $fields): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('forms-bad-'.uniqid());
        $graph = $this->seedSavedGraph($project, 'Old', 0);
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $name = ['rename' => 'rename_graph_', 'width' => 'graph_width_', 'legend' => 'graph_legend_'][$action].$graphId;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.self::BASE.'/graphs/'.$graphId.'/'.$action, [$name => $fields + ['_token' => 'csrf-token']], [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.self::BASE);
        self::assertSame(['error' => ['Loupe could not change the graph. Try again.']], $this->flashes($client));
        $this->em()->clear();
        $stored = $this->em()->find(InsightsSavedGraph::class, $graphId) ?? throw new \LogicException();
        self::assertSame('Old', $stored->name);
        self::assertSame(SavedGraphWidth::Half, $stored->width);
        self::assertTrue($stored->showLegend);
    }

    public function test_the_move_buttons_and_the_drag_form_reorder_the_graphs(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('move');
        $this->seedSavedGraph($project, 'A', 0);
        $b = $this->seedSavedGraph($project, 'B', 1);
        $c = $this->seedSavedGraph($project, 'C', 2);
        $projectId = (string) $project->id;
        $base = '/projects/'.$projectId.self::BASE.'/graphs/';
        $bId = (string) $b->id;
        $cId = (string) $c->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $base.$bId.'/move/up', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertResponseRedirects('/projects/'.$projectId.self::BASE);
        self::assertSame(['B', 'A', 'C'], $this->activeNames($projectId));

        $client->request(Request::METHOD_POST, $base.$bId.'/move/down', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertSame(['A', 'B', 'C'], $this->activeNames($projectId));

        $client->request(Request::METHOD_POST, $base.$cId.'/drag', ['drag_graph_'.$cId => ['steps' => '-2', '_token' => 'csrf-token']], [], self::HEADERS);
        self::assertResponseRedirects('/projects/'.$projectId.self::BASE);
        self::assertSame(['C', 'A', 'B'], $this->activeNames($projectId));
    }

    public function test_a_drag_with_a_bad_step_count_changes_nothing(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('drag-bad');
        $a = $this->seedSavedGraph($project, 'A', 0);
        $this->seedSavedGraph($project, 'B', 1);
        $projectId = (string) $project->id;
        $aId = (string) $a->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        foreach (['', 'x', '99999'] as $steps) {
            $client->request(Request::METHOD_POST, '/projects/'.$projectId.self::BASE.'/graphs/'.$aId.'/drag', ['drag_graph_'.$aId => ['steps' => $steps, '_token' => 'csrf-token']], [], self::HEADERS);
            self::assertResponseRedirects();
            self::assertArrayHasKey('error', $this->flashes($client), $steps);
        }
        self::assertSame(['A', 'B'], $this->activeNames($projectId));
    }

    /** @return iterable<string, array{string, string, array<string, array<string, string>|string>}> */
    public static function writes(): iterable
    {
        yield 'rename' => ['rename', 'rename_graph_', ['name' => 'Hijack']];
        yield 'width' => ['width', 'graph_width_', ['width' => 'full']];
        yield 'legend' => ['legend', 'graph_legend_', ['show' => 'off']];
        yield 'drag' => ['drag', 'drag_graph_', ['steps' => '1']];
        yield 'move up' => ['move/up', '', []];
        yield 'move down' => ['move/down', '', []];
        yield 'remove' => ['remove', '', []];
        yield 'undo' => ['undo', '', []];
    }

    /** @param array<string, string> $fields */
    #[DataProvider('writes')]
    public function test_a_stranger_is_refused_and_a_foreign_graph_id_is_a_not_found(string $action, string $formPrefix, array $fields): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('writes-mine-'.uniqid());
        $mine = $this->seedSavedGraph($project, 'Mine', 0);
        $second = $this->seedSavedGraph($project, 'Second', 1);
        $theirs = $this->seedSavedGraph($this->scenarioProject('writes-theirs-'.uniqid()), 'Theirs', 0);
        $stranger = $this->user($this->em(), 'writes-stranger-'.uniqid().'@example.com');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $post = static fn (string $graphId): array => '' === $formPrefix
            ? ['_csrf_token' => 'csrf-token']
            : [$formPrefix.$graphId => $fields + ['_token' => 'csrf-token']];

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.self::BASE.'/graphs/'.$mine->id.'/'.$action, $post((string) $mine->id), [], self::HEADERS);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.self::BASE.'/graphs/'.$theirs->id.'/'.$action, $post((string) $theirs->id), [], self::HEADERS);
        self::assertResponseStatusCodeSame(404);

        $this->em()->clear();
        $stored = $this->em()->find(InsightsSavedGraph::class, $mine->id) ?? throw new \LogicException();
        self::assertSame('Mine', $stored->name);
        self::assertNull($stored->removedAt);
        self::assertSame(['Mine', 'Second'], $this->activeNames($projectId));
        self::assertNotSame('', (string) $second->id);
    }

    /** @return iterable<string, array{string}> */
    public static function csrfRoutes(): iterable
    {
        yield 'move' => ['move/up'];
        yield 'remove' => ['remove'];
        yield 'undo' => ['undo'];
    }

    #[DataProvider('csrfRoutes')]
    public function test_a_fieldless_action_with_a_wrong_token_is_refused(string $action): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('csrf-'.uniqid());
        $this->seedSavedGraph($project, 'First', 0);
        $second = $this->seedSavedGraph($project, 'Second', 1);
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.self::BASE.'/graphs/'.$second->id.'/'.$action, ['_csrf_token' => 'wrong'], [], self::HEADERS);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['First', 'Second'], $this->activeNames($projectId));
    }

    public function test_a_removed_graph_cannot_be_renamed(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('removed-rename');
        $graph = $this->seedSavedGraph($project, 'Gone', 0, new \DateTimeImmutable('-1 hour'));
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.self::BASE.'/graphs/'.$graphId.'/rename', ['rename_graph_'.$graphId => ['name' => 'Back', '_token' => 'csrf-token']], [], self::HEADERS);

        self::assertResponseStatusCodeSame(404);
        $this->em()->clear();
        self::assertSame('Gone', $this->em()->find(InsightsSavedGraph::class, $graphId)?->name);
    }

    public function test_a_get_on_a_write_route_is_not_allowed(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('write-get');
        $graph = $this->seedSavedGraph($project, 'A', 0);
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.self::BASE.'/graphs/'.$graphId.'/remove');

        self::assertResponseStatusCodeSame(405);
    }

    /** @return list<string> */
    private function activeNames(string $projectId): array
    {
        $this->em()->clear();
        $project = $this->em()->find(Project::class, $projectId) ?? throw new \LogicException();
        $repository = static::getContainer()->get(InsightsSavedGraphRepository::class);
        self::assertInstanceOf(InsightsSavedGraphRepository::class, $repository);

        return array_map(static fn (InsightsSavedGraph $graph): string => $graph->name, $repository->findActive($project));
    }

    /** @return array<string, list<string>> */
    private function flashes(KernelBrowser $client): array
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return $session->getFlashBag()->all();
    }
}
