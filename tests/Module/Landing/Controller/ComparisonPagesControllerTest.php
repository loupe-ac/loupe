<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Site\SiteHost;
use App\Tests\Support\ComparisonFixtures;
use App\Tests\Support\InstalledInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The test app host is `localhost`. */
final class ComparisonPagesControllerTest extends WebTestCase
{
    private string $previousSiteHost = '';

    private string $fixtures = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->previousSiteHost = (string) ($_SERVER['SITE_HOST'] ?? '');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->setSiteHost($this->previousSiteHost);
        if ('' !== $this->fixtures) {
            ComparisonFixtures::remove($this->fixtures);
        }
        parent::tearDown();
    }

    public function test_the_list_names_each_page_and_needs_no_session(): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/compare/'.ComparisonFixtures::SLUG.'"]');
        self::assertSelectorTextContains('h1', 'Compare Loupe with other tools');
    }

    public function test_a_page_shows_its_title_dates_table_and_closing_buttons(): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.ComparisonFixtures::SLUG);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "Loupe vs Fixture's Rival");
        self::assertSelectorTextContains('.lp-legal__updated', 'November 2, 2026');
        self::assertSelectorTextContains('.lp-legal__updated', 'v1.0.0 (2026-10-01)');
        self::assertSelectorExists('.lp-legal__body table');
        self::assertSelectorExists('.lp-legal__body a[rel="noopener noreferrer"][href="https://rival.example/readme"]');
        self::assertCount(2, $client->getCrawler()->filter('.lp-legal__actions a[data-analytics-button-param="compare"]'));
        self::assertSelectorTextContains('.lp-legal__actions', 'Self-host Loupe');
        self::assertSelectorTextContains('.lp-legal__actions', 'Join the waitlist');
        self::assertSelectorExists('.lp-legal__actions a[href$="/operating/install/docker-compose/"]');
        self::assertSelectorExists('.lp-legal__actions a[href="/waitlist"]');
    }

    public function test_a_page_fills_the_meta_description_and_open_graph_description(): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.ComparisonFixtures::SLUG);

        self::assertSelectorExists('meta[name="description"][content="'.ComparisonFixtures::DESCRIPTION.'"]');
        self::assertSelectorExists('meta[property="og:description"][content="'.ComparisonFixtures::DESCRIPTION.'"]');
        self::assertSelectorExists('meta[property="og:title"][content="Loupe vs Fixture\'s Rival — Loupe"]');
    }

    public function test_the_list_fills_the_meta_description_and_open_graph_description(): void
    {
        $client = $this->clientWithSiteHost('localhost');
        $text = 'How Loupe differs from the tools closest to it, and who should pick which.';

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare');

        self::assertSelectorExists('meta[name="description"][content="'.$text.'"]');
        self::assertSelectorExists('meta[property="og:description"][content="'.$text.'"]');
    }

    /** @return iterable<string, array{string}> */
    public static function paths(): iterable
    {
        yield 'list' => ['/compare'];
        yield 'page' => ['/compare/'.ComparisonFixtures::SLUG];
    }

    #[DataProvider('paths')]
    public function test_an_instance_with_no_site_host_answers_404(string $path): void
    {
        $client = $this->clientWithSiteHost('');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $path);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownSlugs(): iterable
    {
        yield 'unknown' => ['/compare/no-such-rival'];
        yield 'upper case' => ['/compare/FIXTURE-RIVAL'];
        yield 'dots' => ['/compare/fixture.rival'];
        yield 'nested' => ['/compare/fixture-rival/extra'];
    }

    #[DataProvider('unknownSlugs')]
    public function test_an_unknown_slug_answers_404(string $path): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $path);

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('paths')]
    public function test_a_split_instance_sends_the_app_host_to_the_site_host(string $path): void
    {
        $client = $this->clientWithSiteHost('loupe.example');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $path, server: ['HTTP_HOST' => 'localhost']);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseHeaderSame('Location', 'https://loupe.example'.$path);
    }

    #[DataProvider('paths')]
    public function test_a_split_instance_serves_the_site_host_without_a_session(string $path): void
    {
        $client = $this->clientWithSiteHost('loupe.example');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $path, server: ['HTTP_HOST' => 'loupe.example']);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_a_legal_page_footer_links_the_list_when_a_site_host_is_set(): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/privacy');

        self::assertSelectorExists('.lp-home-footer a[href="/compare"]');
    }

    public function test_a_legal_page_footer_links_nothing_to_compare_without_a_site_host(): void
    {
        $client = $this->clientWithSiteHost('');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home-footer');
        self::assertSelectorNotExists('.lp-home-footer a[href*="/compare"]');
    }

    private function clientWithSiteHost(string $host): KernelBrowser
    {
        $this->setSiteHost($host);
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $this->fixtures = ComparisonFixtures::install($container, new SiteHost($host, 'http://localhost'), 'http://localhost');
        InstalledInstance::ensure($container);

        return $client;
    }

    private function setSiteHost(string $host): void
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $host;
    }
}
