<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights;

use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsPageShellTest extends WebTestCase
{
    use BridgeScenario;

    /** @return iterable<string, array{string, string}> */
    public static function tabs(): iterable
    {
        yield 'dashboard' => ['/analytics/dashboard', 'Dashboard'];
        yield 'metrics' => ['/analytics/metrics', 'Metrics'];
        yield 'experiments' => ['/analytics/experiments', 'Experiments'];
        yield 'reports' => ['/analytics/reports', 'Reports'];
        yield 'time buckets' => ['/analytics/time-buckets', 'Time buckets'];
    }

    #[DataProvider('tabs')]
    public function test_each_tab_shows_the_analytics_header_tabs_crumb_and_sidebar(string $path, string $activeTab): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'analytics-shell-'.strtolower($activeTab).'@example.com');
        $project = $this->project($em, $owner, 'Shell project');
        $base = '/projects/'.$project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $base.$path);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('Analytics', trim($crawler->filter('title')->text()));
        self::assertSame('Analytics', trim($crawler->filter('h1.lp-workspace-title')->text()));

        $tabs = $crawler->filter('nav.lp-analytics-tabs .lp-tabs__tab');
        self::assertSame(['Dashboard', 'Metrics', 'Experiments', 'Reports', 'Time buckets'], $tabs->each(static fn ($tab): string => trim($tab->text())));
        self::assertSame(
            [$base.'/analytics/dashboard', $base.'/analytics/metrics', $base.'/analytics/experiments', $base.'/analytics/reports', $base.'/analytics/time-buckets'],
            $tabs->each(static fn ($tab): ?string => $tab->attr('href')),
        );
        self::assertSame([$activeTab], $crawler->filter('nav.lp-analytics-tabs [aria-current="page"]')->each(static fn ($tab): string => trim($tab->text())));

        self::assertCount(0, $crawler->filter('.lp-crumbs__link'));
        self::assertSame('Analytics', trim($crawler->filter('.lp-crumbs__here')->text()));

        $active = $crawler->filter('a.lp-sidebar__link--active[aria-current="page"]');
        self::assertCount(1, $active);
        self::assertSame($base.'/analytics', $active->attr('href'));
        self::assertSame('Analytics', trim($active->text()));
    }

    public function test_metrics_links_to_the_dashboard_after_a_save_and_at_no_other_time(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'analytics-shell-saved@example.com');
        $base = '/projects/'.$this->project($em, $owner, 'Saved project')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $base.'/analytics/metrics?saved=0199aaaa-0000-7000-8000-000000000001');
        self::assertSame($base.'/analytics/dashboard', $crawler->filter('[data-metrics-saved-link]')->attr('href'));
        self::assertSame('View on the Dashboard', trim($crawler->filter('[data-metrics-saved-link]')->text()));

        $crawler = $client->request(Request::METHOD_GET, $base.'/analytics/metrics');
        self::assertCount(0, $crawler->filter('[data-metrics-saved-link]'));
    }

    #[DataProvider('tabs')]
    public function test_another_users_project_is_refused(string $path, string $activeTab): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'analytics-shell-theirs-'.strtolower($activeTab).'@example.com');
        $stranger = $this->user($em, 'analytics-shell-stranger-'.strtolower($activeTab).'@example.com');
        $base = '/projects/'.$this->project($em, $owner, 'Their project')->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $base.$path);

        self::assertResponseStatusCodeSame(403);
    }
}
