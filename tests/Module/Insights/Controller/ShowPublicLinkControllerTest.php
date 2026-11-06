<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Project\Entity\Project;
use App\Site\SignedInCookie;
use App\Tests\Module\Insights\InsightsScenario;
use App\Tests\Support\InstalledInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/** The test app host is `localhost`. */
final class ShowPublicLinkControllerTest extends WebTestCase
{
    use InsightsScenario;

    private const string SECRET_ACCOUNT = 'secret-account-zq';

    private const string SECRET_BRIDGE = 'Dev box / 7 zq';

    private string $previousSiteHost = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->previousSiteHost = (string) ($_SERVER['SITE_HOST'] ?? '');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $this->previousSiteHost;
        parent::tearDown();
    }

    public function test_a_shared_graph_shows_in_the_site_frame_with_its_age_and_the_made_with_line(): void
    {
        $client = $this->client('');
        $link = $this->share($this->costGraph($this->scenarioProject('public-ok'), 'Cost per account', MetricGroup::None));

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home-nav');
        self::assertSelectorExists('.lp-home-footer');
        self::assertSelectorNotExists('.lp-sidebar');
        self::assertSelectorTextContains('.lp-public__project', 'Project public-ok');
        self::assertSelectorTextContains('.lp-graph-tile__title', 'Cost per account');
        self::assertSelectorExists('.lp-graph-tile[data-computed-at]');
        self::assertSelectorNotExists('.lp-graph-tile__grip');
        self::assertSelectorNotExists('.lp-graph-tile__actions');
        self::assertSelectorExists('[data-controller~="dashboard-age"] [data-dashboard-age-target="line"]');
        self::assertSelectorTextContains('.lp-public__meta a.lp-public__made-with', 'Made with Loupe');
        self::assertSelectorNotExists('.lp-chart__table');
        self::assertSelectorTextContains('.lp-public__meta', 'Updated 0 min ago');
        self::assertStringNotContainsString('noindex', (string) $client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string, string}> */
    public static function hosts(): iterable
    {
        yield 'app host with no site host' => ['', 'localhost'];
        yield 'site host' => ['loupe.example', 'loupe.example'];
    }

    #[DataProvider('hosts')]
    public function test_the_page_sets_no_cookie(string $siteHost, string $requestHost): void
    {
        $client = $this->client($siteHost);
        $link = $this->share($this->costGraph($this->scenarioProject('public-cookie'), 'Cost', MetricGroup::None));

        $client->request(Request::METHOD_GET, '/s/'.$link->code, server: ['HTTP_HOST' => $requestHost]);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_a_split_instance_sends_the_app_host_to_the_site_host(): void
    {
        $client = $this->client('loupe.example');
        $link = $this->share($this->costGraph($this->scenarioProject('public-redirect'), 'Cost', MetricGroup::None));

        $client->request(Request::METHOD_GET, '/s/'.$link->code, server: ['HTTP_HOST' => 'localhost']);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseHeaderSame('Location', 'https://loupe.example/s/'.$link->code);
    }

    public function test_every_reason_to_hide_the_item_gives_the_same_404_page(): void
    {
        $client = $this->client('');
        $project = $this->scenarioProject('public-404');
        $off = $this->share($this->costGraph($project, 'Off', MetricGroup::None));
        $off->enabled = false;
        $removed = $this->share($this->costGraph($project, 'Removed', MetricGroup::None));
        $removedGraph = $removed->savedGraph ?? throw new \LogicException();
        $removedGraph->removedAt = new \DateTimeImmutable('-1 hour');
        $flagged = $this->share($this->costGraph($project, 'Flagged', MetricGroup::None));
        $this->em()->flush();

        $refusals = [];
        foreach (['aaaaaaaaaaaa' => 'unknown', $off->code => 'off', $removed->code => 'removed'] as $code => $reason) {
            $refusals[$reason] = $this->refusal($client, $code);
        }

        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();
        $refusals['flag'] = $this->refusal($client, $flagged->code);

        self::assertSame(404, $refusals['flag'][1]);
        self::assertCount(1, array_unique(array_map(serialize(...), $refusals)), (string) json_encode($refusals));

        $client->request(Request::METHOD_GET, '/s/'.$flagged->code);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_a_takedown_gives_the_same_404_page_and_a_lift_brings_the_page_back(): void
    {
        $client = $this->client('');
        $project = $this->scenarioProject('public-takedown');
        $link = $this->share($this->costGraph($project, 'Taken', MetricGroup::None));
        $client->request(Request::METHOD_GET, '/s/aaaaaaaaaaaa');
        self::assertResponseStatusCodeSame(404);
        $unknownBody = $this->withoutNoise((string) $client->getResponse()->getContent());
        $takedownId = (string) $this->seedTakedown($project)->id;

        $client->request(Request::METHOD_GET, '/s/'.$link->code);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknownBody, $this->withoutNoise((string) $client->getResponse()->getContent()));
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));

        $this->em()->getConnection()->executeStatement('DELETE FROM insights_public_link_takedowns WHERE id = ?', [$takedownId]);
        $client->request(Request::METHOD_GET, '/s/'.$link->code);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-public__project', 'Project public-takedown');
    }

    public function test_the_admin_bar_shows_on_the_site_host_for_the_admin_hint_and_sets_no_cookie(): void
    {
        $client = $this->client('loupe.example');
        $link = $this->share($this->costGraph($this->scenarioProject('public-bar'), 'Cost', MetricGroup::None));

        $client->request(Request::METHOD_GET, '/s/'.$link->code, server: ['HTTP_HOST' => 'loupe.example']);
        self::assertSelectorNotExists('[data-admin-bar]');

        $client->getCookieJar()->set(new Cookie(SignedInCookie::NAME, '1', 0, '/', 'loupe.example'));
        $client->request(Request::METHOD_GET, '/s/'.$link->code, server: ['HTTP_HOST' => 'loupe.example']);
        self::assertSelectorNotExists('[data-admin-bar]');

        $client->getCookieJar()->set(new Cookie(SignedInCookie::NAME, 'admin', 0, '/', 'loupe.example'));
        $client->request(Request::METHOD_GET, '/s/'.$link->code, server: ['HTTP_HOST' => 'loupe.example']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-admin-bar]');
        $action = $client->getCrawler()->filter('[data-admin-bar] a');
        self::assertCount(1, $action);
        self::assertSame('http://localhost/admin/public-links/'.$link->code.'/take-down', $action->attr('href'));
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_the_admin_bar_shows_on_the_app_host_for_a_signed_in_admin_only(): void
    {
        $client = $this->client('');
        $link = $this->share($this->costGraph($this->scenarioProject('public-bar-app'), 'Cost', MetricGroup::None));
        $plain = $this->user($this->em(), 'bar-plain-'.uniqid().'@example.com');
        $admin = $this->user($this->em(), 'bar-admin-'.uniqid().'@example.com');
        $admin->roles = ['ROLE_ADMIN'];
        $this->em()->flush();

        $client->request(Request::METHOD_GET, '/s/'.$link->code);
        self::assertSelectorNotExists('[data-admin-bar]');
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));

        $client->loginUser($plain);
        $client->request(Request::METHOD_GET, '/s/'.$link->code);
        self::assertSelectorNotExists('[data-admin-bar]');

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/s/'.$link->code);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-admin-bar]');
        self::assertSelectorTextContains('[data-admin-bar] a', 'Take down the public links of this project');
    }

    public function test_a_code_of_the_wrong_shape_does_not_route(): void
    {
        $client = $this->client('');

        foreach (['/s/short', '/s/AAAAAAAAAAAA', '/s/aaaaaaaaaaaaa', '/s/aaaaaaaaaaa1'] as $path) {
            $client->request(Request::METHOD_GET, $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function test_a_broken_graph_says_it_cannot_be_drawn_and_gives_no_reason(): void
    {
        $client = $this->client('');
        $graph = $this->seedSavedGraph($this->scenarioProject('public-broken'), 'Tests', 0);
        $link = $this->share($graph);

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-graph-tile__broken', 'This graph cannot be drawn right now.');
        self::assertSelectorNotExists('.lp-chart');
        self::assertStringNotContainsString('tests', strtolower((string) $client->getCrawler()->filter('.lp-graph-tile__broken')->text()));
    }

    public function test_requests_above_the_limit_get_429(): void
    {
        $client = $this->client('');
        $link = $this->share($this->costGraph($this->scenarioProject('public-limit'), 'Cost', MetricGroup::None));
        static::getContainer()->set('limiter.public_link', new RateLimiterFactory(
            ['id' => 'public_link', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));

        $client->request(Request::METHOD_GET, '/s/'.$link->code);
        self::assertResponseIsSuccessful();
        $client->request(Request::METHOD_GET, '/s/'.$link->code);
        self::assertResponseIsSuccessful();
        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
    }

    public function test_the_page_source_holds_no_account_or_bridge_name_for_a_split_that_compares(): void
    {
        $client = $this->client('');
        $project = $this->scenarioProject('public-leak');
        $this->seedLeakyRuns($project);

        $graph = $this->costGraph($project, 'By account', MetricGroup::Account);
        $graph->compare = MetricCompare::Previous;
        $this->em()->flush();
        $link = $this->share($graph);

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        $source = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Account 1', $source);
        self::assertStringContainsString('Account 2', $source);
        self::assertStringNotContainsString(self::SECRET_ACCOUNT, $source);
        self::assertStringNotContainsString('previous-only-zq', $source);
    }

    public function test_the_page_source_holds_no_bridge_name_for_a_bridge_split_and_a_usage_split(): void
    {
        $client = $this->client('');
        $project = $this->scenarioProject('public-bridge');
        $em = $this->em();
        $bridge = $this->seedBridge($em, $project->owner, projects: [(string) $project->id]);
        $bridge->name = self::SECRET_BRIDGE;
        $em->flush();
        $run = $this->seedRun($em, $project, cardNumber: 1, bridgeId: $bridge->id, endedAt: new \DateTimeImmutable('-5 days'));
        $this->seedUsage($em, $run, costUsd: '3.00');
        $this->seedAccountReading($em, $bridge, new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s'), account: self::SECRET_ACCOUNT, window: 'session');

        $byBridge = $this->costGraph($project, 'By bridge', MetricGroup::Bridge);
        $usage = $this->seedSavedGraph($project, 'Usage', 1);
        $usage->metric = 'account-usage';
        $usage->unit = MetricUnit::Reading;
        $usage->statistic = MetricStatistic::Max;
        $usage->group = MetricGroup::AccountWindow;
        $usage->range = MetricRange::ThirtyDays;
        $em->flush();

        foreach ([$byBridge, $usage] as $graph) {
            $link = $this->share($graph);
            $client->request(Request::METHOD_GET, '/s/'.$link->code);
            self::assertResponseIsSuccessful();
            $source = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Bridge 1', $source, $graph->name);
            self::assertStringNotContainsString('Dev box', $source);
            self::assertStringNotContainsString('zq', $source);
        }
    }

    /**
     * The exception behind the 404, which alone decides the error page a visitor
     * sees. The debug page of the test kernel is not stable between requests.
     *
     * @return array{class-string, int, string, string, int}
     */
    private function refusal(KernelBrowser $client, string $code): array
    {
        $client->catchExceptions(false);
        try {
            $client->request(Request::METHOD_GET, '/s/'.$code);
        } catch (HttpExceptionInterface $exception) {
            return [$exception::class, $exception->getStatusCode(), $exception->getMessage(), $exception->getFile(), $exception->getLine()];
        } finally {
            $client->catchExceptions(true);
        }

        self::fail(\sprintf('The code %s showed a page.', $code));
    }

    private function seedLeakyRuns(Project $project): void
    {
        $em = $this->em();
        foreach ([[self::SECRET_ACCOUNT, '-5 days'], ['other-zq', '-6 days'], ['previous-only-zq', '-40 days']] as $index => [$account, $when]) {
            $run = $this->seedRun($em, $project, cardNumber: $index + 1, endedAt: new \DateTimeImmutable($when));
            $this->seedUsage($em, $run, costUsd: '3.00');
            $em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET account = :account WHERE run_id = :run', ['account' => $account, 'run' => (string) $run->id]);
        }
    }

    private function costGraph(Project $project, string $name, MetricGroup $group): InsightsSavedGraph
    {
        $graph = $this->seedSavedGraph($project, $name, 0);
        $graph->metric = 'cost';
        $graph->unit = MetricUnit::Run;
        $graph->statistic = MetricStatistic::Sum;
        $graph->group = $group;
        $graph->range = MetricRange::ThirtyDays;
        $graph->compare = MetricCompare::Off;
        $this->em()->flush();

        return $graph;
    }

    private function share(InsightsSavedGraph $graph): PublicLink
    {
        $link = PublicLink::forGraph($graph);
        $this->em()->persist($link);
        $this->em()->flush();

        return $link;
    }

    private function client(string $siteHost): KernelBrowser
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $siteHost;
        $client = static::createClient();
        $client->disableReboot();
        InstalledInstance::ensure(static::getContainer());

        return $client;
    }
}
