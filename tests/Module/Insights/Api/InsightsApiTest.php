<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Api;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Entity\InsightsProjectSettings;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Insights\Repository\InsightsProjectSettingsRepository;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\View\MetricChartType;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\PublicSpec;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The graph, bucket rule and analytics settings routes of the public API under /api/v1/projects/{project}. */
final class InsightsApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private OAuthScenario $scenario;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->owner = $this->scenario->createUser('insights-api-'.uniqid().'@example.com');
        $this->project = $this->makeProject($this->owner, 'insights-shop');
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    public function test_a_graph_is_saved_last_with_its_defaults_and_a_location(): void
    {
        $this->send(Request::METHOD_POST, $this->graphs(), '{"name":"Cost","settings":{"metric":"cost","statistic":"sum","unit":"run"}}');

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        PublicSpec::assertMatches('Graph', $body);
        self::assertSame('Cost', $body['name']);
        self::assertSame(0, $body['position']);
        self::assertSame('half', $body['width']);
        self::assertTrue($body['showLegend']);
        self::assertSame(['metric' => 'cost', 'unit' => 'run', 'statistic' => 'sum', 'group' => 'none', 'range' => 'ninety-days', 'bucket' => 'week', 'compare' => 'off', 'chart' => 'line'], $body['settings']);
        self::assertNull($body['formula']);
        self::assertNull($body['broken']);
        self::assertNull($body['undoUntil']);
        self::assertStringEndsWith($this->graphs().'/'.$body['id'], (string) $this->client->getResponse()->headers->get('Location'));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
        self::assertCount(1, $this->storedGraphs());

        $this->send(Request::METHOD_POST, $this->graphs(), '{"settings":{"metric":"cost","statistic":"sum","unit":"run"}}');
        self::assertSame(1, $this->json()['position']);
    }

    public function test_a_formula_graph_is_saved_with_its_rows(): void
    {
        $this->send(Request::METHOD_POST, $this->graphs(), json_encode([
            'settings' => ['unit' => 'card'],
            'rows' => [['letter' => 'A', 'metric' => 'cache-read-tokens', 'statistic' => 'sum'], ['letter' => 'B', 'metric' => 'cache-write-tokens', 'statistic' => 'sum']],
            'formula' => 'A / B',
            'format' => 'number',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        PublicSpec::assertMatches('Graph', $body);
        self::assertSame('A / B', $body['formula']['formula']);
        self::assertSame('number', $body['formula']['format']);
        self::assertSame([['letter' => 'A', 'metric' => 'cache-read-tokens', 'statistic' => 'sum'], ['letter' => 'B', 'metric' => 'cache-write-tokens', 'statistic' => 'sum']], $body['formula']['rows']);
        self::assertSame('cache-read-tokens', $body['settings']['metric']);
    }

    public function test_a_repeated_idempotency_key_saves_one_graph(): void
    {
        $body = '{"settings":{"metric":"cost","statistic":"sum","unit":"run"}}';
        $this->send(Request::METHOD_POST, $this->graphs(), $body, ['HTTP_IDEMPOTENCY_KEY' => 'save-1']);
        $first = $this->json();
        $this->send(Request::METHOD_POST, $this->graphs(), $body, ['HTTP_IDEMPOTENCY_KEY' => 'save-1']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $this->json());
        self::assertCount(1, $this->storedGraphs());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function badSaves(): iterable
    {
        yield 'no settings' => ['{}', 'settings', 'validation_failed'];
        yield 'a null settings' => ['{"settings":null}', 'settings', 'validation_failed'];
        yield 'no unit' => ['{"settings":{"metric":"cost","statistic":"sum"}}', 'settings.unit', 'validation_failed'];
        yield 'neither a metric nor rows' => ['{"settings":{"unit":"run"}}', 'settings.metric', 'metric_conflict'];
        yield 'a metric with rows' => ['{"settings":{"unit":"run","metric":"cost","statistic":"sum"},"rows":[{"letter":"A","metric":"cost","statistic":"sum"}],"formula":"A"}', 'settings.metric', 'metric_conflict'];
        yield 'an unknown metric' => ['{"settings":{"unit":"run","metric":"nope","statistic":"sum"}}', 'settings.metric', 'metric_invalid'];
        yield 'an unknown range' => ['{"settings":{"unit":"run","metric":"cost","statistic":"sum","range":"forever"}}', 'settings.range', 'range_invalid'];
        yield 'a locked chart' => ['{"settings":{"unit":"run","metric":"cost","statistic":"median","chart":"donut"}}', 'settings.chart', 'chart_needs_several_lines'];
        yield 'a row with a comma' => ['{"settings":{"unit":"run"},"rows":[{"letter":"A","metric":"cost,x","statistic":"sum"}],"formula":"A"}', 'rows[0].metric', 'validation_failed'];
    }

    #[DataProvider('badSaves')]
    public function test_a_save_the_server_refuses_is_a_validation_problem_and_saves_nothing(string $body, string $field, string $code): void
    {
        $this->send(Request::METHOD_POST, $this->graphs(), $body);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->json();
        self::assertSame('validation_failed', $problem['code']);
        self::assertContains($field, array_column($problem['errors'], 'field'));
        self::assertSame([], $this->storedGraphs());
    }

    public function test_a_name_over_the_column_is_refused(): void
    {
        $this->send(Request::METHOD_POST, $this->graphs(), json_encode(['name' => str_repeat('a', 121), 'settings' => ['metric' => 'cost', 'statistic' => 'sum', 'unit' => 'run']], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('name', $this->json()['errors'][0]['field']);
    }

    public function test_a_full_dashboard_refuses_a_save_with_dashboard_full(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $this->graph('Graph '.$i, $i);
        }

        $this->send(Request::METHOD_POST, $this->graphs(), '{"settings":{"metric":"cost","statistic":"sum","unit":"run"}}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('dashboard_full', $this->json()['code']);
        self::assertCount(12, $this->storedGraphs());
    }

    public function test_the_list_holds_the_graphs_in_dashboard_order_and_a_cursor_walks_it(): void
    {
        $this->graph('Second', 5);
        $this->graph('First', 1);
        $this->graph('Gone', 3, new \DateTimeImmutable('-1 hour'));

        $this->send(Request::METHOD_GET, $this->graphs());
        self::assertResponseStatusCodeSame(200);
        $all = $this->json();
        PublicSpec::assertMatches('GraphList', $all);
        self::assertSame(['First', 'Second'], array_column($all['items'], 'name'));
        self::assertSame([0, 1], array_column($all['items'], 'position'));
        self::assertNull($all['next']);

        $this->send(Request::METHOD_GET, $this->graphs().'?limit=1');
        $first = $this->json();
        self::assertSame(['First'], array_column($first['items'], 'name'));
        self::assertIsString($first['next']);
        self::assertStringContainsString('rel="next"', (string) $this->client->getResponse()->headers->get('Link'));

        $this->send(Request::METHOD_GET, $first['next']);
        $second = $this->json();
        self::assertSame(['Second'], array_column($second['items'], 'name'));
        self::assertSame([1], array_column($second['items'], 'position'));
        self::assertNull($second['next']);
    }

    public function test_the_removed_list_holds_the_graphs_that_a_restore_can_bring_back(): void
    {
        $this->graph('On show', 0);
        $this->graph('Gone', 1, new \DateTimeImmutable('-1 hour'));
        $this->graph('Too old', 2, new \DateTimeImmutable('-3 days'));

        $this->send(Request::METHOD_GET, $this->graphs().'?removed=true');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['Gone'], array_column($body['items'], 'name'));
        self::assertNull($body['items'][0]['position']);
        self::assertIsString($body['items'][0]['undoUntil']);
        PublicSpec::assertMatches('GraphList', $body);
    }

    public function test_a_broken_cursor_a_changed_filter_and_a_bad_flag_are_refused(): void
    {
        $this->graph('One', 0);
        $this->graph('Two', 1);
        $this->send(Request::METHOD_GET, $this->graphs().'?limit=1');
        $next = $this->json()['next'];

        $this->send(Request::METHOD_GET, $this->graphs().'?cursor=nonsense');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $next.'&removed=true');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->graphs().'?removed=maybe');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('removed', $this->json()['errors'][0]['field']);
    }

    public function test_one_graph_is_read_and_a_missing_removed_or_foreign_graph_is_graph_not_found(): void
    {
        $graph = $this->graph('Mine', 0);
        $gone = $this->graph('Gone', 1, new \DateTimeImmutable('-1 hour'));
        $theirs = $this->graph('Theirs', 0, project: $this->makeProject($this->scenario->createUser('theirs-'.uniqid().'@example.com'), 'their-board'));

        $this->send(Request::METHOD_GET, $this->graphs().'/'.$graph->id);
        self::assertResponseStatusCodeSame(200);
        PublicSpec::assertMatches('Graph', $this->json());
        self::assertSame('Mine', $this->json()['name']);

        foreach ([$gone->id, $theirs->id, '0198a3c4-0000-7000-8000-000000000001'] as $id) {
            $this->send(Request::METHOD_GET, $this->graphs().'/'.$id);
            self::assertResponseStatusCodeSame(404, (string) $id);
            self::assertSame('graph_not_found', $this->json()['code'], (string) $id);
        }

        $this->send(Request::METHOD_GET, $this->graphs().'/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('route_not_found', $this->json()['code']);
    }

    public function test_one_patch_changes_the_name_the_settings_the_width_the_legend_and_the_place(): void
    {
        $this->graph('First', 0);
        $second = $this->graph('Second', 1);

        $this->send(Request::METHOD_PATCH, $this->graphs().'/'.$second->id, '{"name":"Renamed","settings":{"metric":"cost","statistic":"sum","range":"seven-days","bucket":"day"},"width":"full","showLegend":false,"position":0}');

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        PublicSpec::assertMatches('Graph', $body);
        self::assertSame('Renamed', $body['name']);
        self::assertSame('full', $body['width']);
        self::assertFalse($body['showLegend']);
        self::assertSame(0, $body['position']);
        self::assertSame('seven-days', $body['settings']['range']);
        self::assertSame('sum', $body['settings']['statistic']);
        self::assertSame(['Renamed', 'First'], array_map(static fn (InsightsSavedGraph $graph): string => $graph->name, $this->storedGraphs()));
    }

    public function test_a_patch_that_names_nothing_keeps_the_graph(): void
    {
        $graph = $this->graph('Same', 0);

        $this->send(Request::METHOD_PATCH, $this->graphs().'/'.$graph->id, '{}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Same', $this->json()['name']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badPatches(): iterable
    {
        yield 'a null name' => ['{"name":null}', 'name'];
        yield 'a blank name' => ['{"name":"   "}', 'name'];
        yield 'a null width' => ['{"width":null}', 'width'];
        yield 'a width that is no width' => ['{"width":"huge"}', 'width'];
        yield 'a negative place' => ['{"position":-1}', 'position'];
        yield 'a bare statistic on a formula graph' => ['{"settings":{"statistic":"mean"}}', 'settings.statistic'];
        yield 'a clear with rows' => ['{"clearFormula":true,"rows":[{"letter":"A","metric":"cost","statistic":"sum"}]}', 'clearFormula'];
        yield 'a range the bucket does not take' => ['{"settings":{"range":"seven-days"}}', 'settings.bucket'];
    }

    #[DataProvider('badPatches')]
    public function test_a_change_the_server_refuses_is_a_validation_problem_and_changes_nothing(string $body, string $field): void
    {
        $graph = $this->formulaGraph('Ratio', 0);
        $before = $graph->name;

        $this->send(Request::METHOD_PATCH, $this->graphs().'/'.$graph->id, $body);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->json();
        self::assertSame('validation_failed', $problem['code']);
        self::assertContains($field, array_column($problem['errors'], 'field'));
        self::assertSame([$before], array_map(static fn (InsightsSavedGraph $stored): string => $stored->name, $this->storedGraphs()));
    }

    public function test_a_formula_graph_goes_back_to_one_metric_with_clear_formula(): void
    {
        $graph = $this->formulaGraph('Ratio', 0);

        $this->send(Request::METHOD_PATCH, $this->graphs().'/'.$graph->id, '{"clearFormula":true}');

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['formula']);
        self::assertSame('cache-read-tokens', $this->json()['settings']['metric']);
    }

    public function test_a_patch_of_a_removed_or_foreign_graph_is_graph_not_found(): void
    {
        $gone = $this->graph('Gone', 0, new \DateTimeImmutable('-1 hour'));
        $theirs = $this->graph('Theirs', 0, project: $this->makeProject($this->scenario->createUser('theirs-'.uniqid().'@example.com'), 'their-board'));

        foreach ([$gone, $theirs] as $graph) {
            $this->send(Request::METHOD_PATCH, $this->graphs().'/'.$graph->id, '{"name":"Back"}');
            self::assertResponseStatusCodeSame(404);
            self::assertSame('graph_not_found', $this->json()['code']);
        }
        $this->em->clear();
        self::assertSame('Theirs', $this->em->find(InsightsSavedGraph::class, $theirs->id)?->name);
    }

    public function test_a_graph_is_removed_at_once_and_comes_back_with_a_restore(): void
    {
        $graph = $this->graph('Mine', 0);

        $this->send(Request::METHOD_DELETE, $this->graphs().'/'.$graph->id);

        self::assertResponseStatusCodeSame(200);
        $removed = $this->json();
        PublicSpec::assertMatches('GraphRemoveResult', $removed);
        self::assertGreaterThan(new \DateTimeImmutable('+23 hours'), new \DateTimeImmutable($removed['undoUntil']));
        $this->send(Request::METHOD_GET, $this->graphs());
        self::assertSame([], $this->json()['items']);

        $this->send(Request::METHOD_DELETE, $this->graphs().'/'.$graph->id);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('graph_not_found', $this->json()['code']);

        $this->send(Request::METHOD_POST, $this->graphs().'/'.$graph->id.'/restore');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Mine', $this->json()['name']);
        self::assertSame(0, $this->json()['position']);

        $this->send(Request::METHOD_POST, $this->graphs().'/'.$graph->id.'/restore');
        self::assertResponseStatusCodeSame(200);
    }

    public function test_a_restore_after_the_day_is_restore_expired_and_a_full_dashboard_refuses_it(): void
    {
        $old = $this->graph('Old', 0, new \DateTimeImmutable('-3 days'));

        $this->send(Request::METHOD_POST, $this->graphs().'/'.$old->id.'/restore');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('restore_expired', $this->json()['code']);

        $recent = $this->graph('Recent', 1, new \DateTimeImmutable('-1 hour'));
        for ($i = 2; $i < 14; ++$i) {
            $this->graph('Graph '.$i, $i);
        }
        $this->send(Request::METHOD_POST, $this->graphs().'/'.$recent->id.'/restore');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('dashboard_full', $this->json()['code']);

        $this->send(Request::METHOD_POST, $this->graphs().'/0198a3c4-0000-7000-8000-000000000001/restore');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('graph_not_found', $this->json()['code']);
    }

    public function test_a_bucket_rule_is_created_last_and_the_list_walks_the_apply_order(): void
    {
        $this->send(Request::METHOD_POST, $this->rules(), '{"pattern":"LSP","bucket":"navigation"}');
        self::assertResponseStatusCodeSame(201);
        $first = $this->json();
        PublicSpec::assertMatches('BucketRule', $first);
        self::assertSame('LSP', $first['pattern']);
        self::assertSame('navigation', $first['bucket']);
        self::assertSame(0, $first['position']);
        self::assertNotSame('', (string) $this->client->getResponse()->headers->get('Location'));

        $this->send(Request::METHOD_POST, $this->rules(), '{"pattern":"mcp__*","bucket":"tools"}');
        self::assertSame(1, $this->json()['position']);

        $this->send(Request::METHOD_GET, $this->rules().'?limit=1');
        $page = $this->json();
        PublicSpec::assertMatches('BucketRuleList', $page);
        self::assertSame(['LSP'], array_column($page['items'], 'pattern'));
        $this->send(Request::METHOD_GET, $page['next']);
        self::assertSame(['mcp__*'], array_column($this->json()['items'], 'pattern'));
        self::assertNull($this->json()['next']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badRules(): iterable
    {
        yield 'no fields' => ['{}', 'pattern'];
        yield 'a blank pattern' => ['{"pattern":"  ","bucket":"tools"}', 'pattern'];
        yield 'a bucket with a capital' => ['{"pattern":"LSP","bucket":"Tools"}', 'bucket'];
        yield 'a pattern too long' => ['{"pattern":"'.str_repeat('a', 121).'","bucket":"tools"}', 'pattern'];
    }

    #[DataProvider('badRules')]
    public function test_a_rule_the_server_refuses_is_a_validation_problem(string $body, string $field): void
    {
        $this->send(Request::METHOD_POST, $this->rules(), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertContains($field, array_column($this->json()['errors'], 'field'));
        self::assertSame([], $this->storedRules());
    }

    public function test_a_project_holds_at_most_fifty_rules(): void
    {
        for ($i = 0; $i < InsightsBucketRule::MAX_PER_PROJECT; ++$i) {
            $this->rule('p'.$i, 'b'.$i, $i);
        }

        $this->send(Request::METHOD_POST, $this->rules(), '{"pattern":"extra","bucket":"extra"}');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('bucket_rule_limit_reached', $this->json()['code']);
    }

    public function test_a_rule_moves_to_a_place_and_the_others_keep_their_order(): void
    {
        $this->rule('a', 'one', 0);
        $this->rule('b', 'two', 1);
        $last = $this->rule('c', 'three', 2);

        $this->send(Request::METHOD_PATCH, $this->rules().'/'.$last->id, '{"position":0}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->json()['position']);
        self::assertSame(['c', 'a', 'b'], array_map(static fn (InsightsBucketRule $rule): string => $rule->pattern, $this->storedRules()));

        $this->send(Request::METHOD_PATCH, $this->rules().'/'.$last->id, '{"position":99}');
        self::assertSame(2, $this->json()['position']);
        self::assertSame(['a', 'b', 'c'], array_map(static fn (InsightsBucketRule $rule): string => $rule->pattern, $this->storedRules()));

        foreach (['{}', '{"position":null}', '{"position":-1}'] as $body) {
            $this->send(Request::METHOD_PATCH, $this->rules().'/'.$last->id, $body);
            self::assertResponseStatusCodeSame(422, $body);
        }
    }

    public function test_a_rule_is_deleted_and_a_foreign_or_missing_rule_is_bucket_rule_not_found(): void
    {
        $rule = $this->rule('a', 'one', 0);
        $theirs = $this->rule('x', 'theirs', 0, $this->makeProject($this->scenario->createUser('theirs-'.uniqid().'@example.com'), 'their-board'));

        foreach ([Request::METHOD_DELETE => '', Request::METHOD_PATCH => '{"position":0}'] as $method => $body) {
            $this->send($method, $this->rules().'/'.$theirs->id, $body);
            self::assertResponseStatusCodeSame(404, $method);
            self::assertSame('bucket_rule_not_found', $this->json()['code'], $method);
        }

        $this->send(Request::METHOD_DELETE, $this->rules().'/'.$rule->id);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['deleted' => (string) $rule->id], $this->json());
        PublicSpec::assertMatches('BucketRuleDeleteResult', $this->json());
        self::assertSame([], $this->storedRules());

        $this->send(Request::METHOD_DELETE, $this->rules().'/'.$rule->id);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_analytics_settings_read_with_the_values_a_new_analysis_takes(): void
    {
        $this->send(Request::METHOD_GET, $this->settings());

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        PublicSpec::assertMatches('AnalyticsSettings', $body);
        self::assertNull($body['defaultModel']);
        self::assertNull($body['defaultEffort']);
        self::assertFalse($body['collectFullText']);
        self::assertSame([], $body['subcommandPrograms']);
        self::assertNotSame('', $body['model']);
        self::assertContains($body['effort'], ['low', 'medium', 'high', 'xhigh', 'max']);
    }

    public function test_a_patch_changes_only_the_settings_it_names_and_null_clears_a_default(): void
    {
        $this->send(Request::METHOD_PATCH, $this->settings(), '{"defaultModel":"opus","defaultEffort":"high","collectFullText":true,"subcommandPrograms":["git","npm"]}');
        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        PublicSpec::assertMatches('AnalyticsSettings', $body);
        self::assertSame('opus', $body['defaultModel']);
        self::assertSame('high', $body['effort']);
        self::assertTrue($body['collectFullText']);
        self::assertSame(['git', 'npm'], $body['subcommandPrograms']);

        $this->send(Request::METHOD_PATCH, $this->settings(), '{"collectFullText":false}');
        self::assertSame('opus', $this->json()['defaultModel']);
        self::assertFalse($this->json()['collectFullText']);
        self::assertSame(['git', 'npm'], $this->json()['subcommandPrograms']);

        $this->send(Request::METHOD_PATCH, $this->settings(), '{"defaultModel":null,"defaultEffort":null,"subcommandPrograms":[]}');
        $cleared = $this->json();
        self::assertNull($cleared['defaultModel']);
        self::assertNull($cleared['defaultEffort']);
        self::assertSame([], $cleared['subcommandPrograms']);
        $this->em->clear();
        $stored = static::getContainer()->get(InsightsProjectSettingsRepository::class)->findForProject($this->project);
        self::assertInstanceOf(InsightsProjectSettings::class, $stored);
        self::assertNull($stored->defaultModel);
        self::assertNull($stored->subcommandPrograms);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function badSettings(): iterable
    {
        yield 'a model with a space' => ['{"defaultModel":"two words"}', 'defaultModel', 'invalid_model'];
        yield 'an effort that is no effort' => ['{"defaultEffort":"extreme"}', 'defaultEffort', 'invalid_effort'];
        yield 'a program with a slash' => ['{"subcommandPrograms":["a/b"]}', 'subcommandPrograms', 'invalid_subcommand_programs'];
    }

    #[DataProvider('badSettings')]
    public function test_a_setting_the_handler_refuses_is_a_validation_problem(string $body, string $field, string $code): void
    {
        $this->send(Request::METHOD_PATCH, $this->settings(), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($field, $this->json()['errors'][0]['field']);
        self::assertSame($code, $this->json()['errors'][0]['code']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function routes(): iterable
    {
        yield 'list graphs' => [Request::METHOD_GET, '/graphs', ''];
        yield 'save a graph' => [Request::METHOD_POST, '/graphs', '{"settings":{"metric":"cost","statistic":"sum","unit":"run"}}'];
        yield 'list rules' => [Request::METHOD_GET, '/bucket-rules', ''];
        yield 'create a rule' => [Request::METHOD_POST, '/bucket-rules', '{"pattern":"LSP","bucket":"navigation"}'];
        yield 'read the settings' => [Request::METHOD_GET, '/analytics/settings', ''];
        yield 'change the settings' => [Request::METHOD_PATCH, '/analytics/settings', '{"collectFullText":true}'];
    }

    #[DataProvider('routes')]
    public function test_a_project_of_another_owner_reads_as_a_missing_project_and_stays_untouched(string $method, string $suffix, string $body): void
    {
        $other = $this->scenario->createUser('insights-other-'.uniqid().'@example.com');
        $otherProject = $this->makeProject($other, 'their-insights');

        $this->send($method, '/api/v1/projects/'.$otherProject->id.$suffix, $body);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame([], $this->storedGraphs($otherProject));
        self::assertSame([], $this->storedRules($otherProject));
    }

    #[DataProvider('routes')]
    public function test_a_request_with_no_credential_is_refused(string $method, string $suffix, string $body): void
    {
        $this->send($method, '/api/v1/projects/'.$this->project->id.$suffix, $body, token: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->json()['code']);
    }

    #[DataProvider('routes')]
    public function test_a_credential_without_the_public_scope_is_refused(string $method, string $suffix, string $body): void
    {
        $this->send($method, '/api/v1/projects/'.$this->project->id.$suffix, $body, token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'agent mcp'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('credential_not_allowed', $this->json()['code']);
    }

    #[DataProvider('routes')]
    public function test_a_credential_bound_to_another_project_reaches_nothing_here(string $method, string $suffix, string $body): void
    {
        $bound = $this->makeProject($this->owner, 'bound-insights');

        $this->send($method, '/api/v1/projects/'.$this->project->id.$suffix, $body, token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'mcp public', $bound));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
    }

    private function graphs(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/graphs';
    }

    private function rules(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/bucket-rules';
    }

    private function settings(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/analytics/settings';
    }

    private function makeProject(User $owner, string $name): Project
    {
        $project = new Project($owner, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function graph(string $name, int $position, ?\DateTimeImmutable $removedAt = null, ?Project $project = null): InsightsSavedGraph
    {
        $graph = new InsightsSavedGraph(
            $project ?? $this->project,
            $name,
            'cost',
            MetricUnit::Run,
            MetricStatistic::Sum,
            MetricGroup::None,
            MetricRange::NinetyDays,
            MetricBucket::Week,
            MetricCompare::Off,
            MetricChartType::Line,
            SavedGraphWidth::Half,
            true,
            $position,
            removedAt: $removedAt,
        );
        $this->em->persist($graph);
        $this->em->flush();

        return $graph;
    }

    private function formulaGraph(string $name, int $position): InsightsSavedGraph
    {
        $graph = new InsightsSavedGraph(
            $this->project,
            $name,
            'cache-read-tokens',
            MetricUnit::Card,
            MetricStatistic::Sum,
            MetricGroup::None,
            MetricRange::NinetyDays,
            MetricBucket::Week,
            MetricCompare::Off,
            MetricChartType::Line,
            SavedGraphWidth::Half,
            true,
            $position,
            formulaRows: 'A:cache-read-tokens:sum,B:cache-write-tokens:sum',
            formula: 'A / B',
            format: 'auto',
        );
        $this->em->persist($graph);
        $this->em->flush();

        return $graph;
    }

    private function rule(string $pattern, string $bucket, int $position, ?Project $project = null): InsightsBucketRule
    {
        $rule = new InsightsBucketRule($project ?? $this->project, $pattern, $bucket, $position);
        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }

    /** @return list<InsightsSavedGraph> the graphs on show, read fresh */
    private function storedGraphs(?Project $project = null): array
    {
        $this->em->clear();

        return static::getContainer()->get(InsightsSavedGraphRepository::class)->findActive($project ?? $this->project);
    }

    /** @return list<InsightsBucketRule> */
    private function storedRules(?Project $project = null): array
    {
        $this->em->clear();

        return static::getContainer()->get(InsightsBucketRuleRepository::class)->findOrdered($project ?? $this->project);
    }

    /** @param array<string, string> $server */
    private function send(string $method, string $url, string $body = '', array $server = [], ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request($method, $url, server: $server + ['CONTENT_TYPE' => 'application/json'] + (null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]), content: $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
