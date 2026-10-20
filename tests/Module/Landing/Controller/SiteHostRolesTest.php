<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Tests\Support\InstalledInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The test app host is `localhost`. The site host is `loupe.example`, so the two differ. */
final class SiteHostRolesTest extends WebTestCase
{
    private const string SITE = 'loupe.example';

    private string $previousSiteHost = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->previousSiteHost = (string) ($_SERVER['SITE_HOST'] ?? '');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->setSiteHost($this->previousSiteHost);
        parent::tearDown();
    }

    private function split(): KernelBrowser
    {
        $this->setSiteHost(self::SITE);
        $client = static::createClient();
        InstalledInstance::ensure(static::getContainer());

        return $client;
    }

    /** @param array<string, string> $server */
    private function get(KernelBrowser $client, string $host, string $path, array $server = [], string $method = Request::METHOD_GET): void
    {
        $client->request($method, $path, server: ['HTTP_HOST' => $host] + $server);
    }

    /** @return iterable<string, array{string}> */
    public static function sitePaths(): iterable
    {
        yield 'home' => ['/'];
        yield 'privacy' => ['/privacy'];
        yield 'terms' => ['/terms'];
        yield 'ai policy' => ['/ai-policy'];
        yield 'sitemap' => ['/sitemap.xml'];
        yield 'robots' => ['/robots.txt'];
        yield 'liveness' => ['/livez'];
    }

    #[DataProvider('sitePaths')]
    public function test_the_site_host_answers_a_site_page_without_a_session(string $path): void
    {
        $client = $this->split();

        $this->get($client, self::SITE, $path);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        self::assertCount(0, $client->getCookieJar()->all());
    }

    /** @return iterable<string, array{string}> */
    public static function appPaths(): iterable
    {
        yield 'sign in' => ['/login'];
        yield 'register' => ['/register'];
        yield 'projects' => ['/projects'];
        yield 'mcp' => ['/mcp'];
        yield 'api' => ['/api/projects'];
        yield 'install script' => ['/install.sh'];
        yield 'widget script' => ['/site-review/widget.js'];
        yield 'webhook' => ['/webhooks/stripe'];
        yield 'unknown path' => ['/no-such-page'];
    }

    #[DataProvider('appPaths')]
    public function test_the_site_host_answers_an_app_path_with_a_plain_404(string $path): void
    {
        $client = $this->split();

        $this->get($client, self::SITE, $path, method: str_starts_with($path, '/webhooks/') ? Request::METHOD_POST : Request::METHOD_GET);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_same_paths_still_answer_on_the_app_host(): void
    {
        $client = $this->split();

        $this->get($client, 'localhost', '/login');

        self::assertResponseIsSuccessful();
    }

    public function test_a_forged_site_host_in_the_forwarded_header_changes_nothing_on_the_app_host(): void
    {
        $client = $this->split();

        $this->get($client, 'localhost', '/login', ['HTTP_X_FORWARDED_HOST' => 'elsewhere.example']);

        self::assertResponseIsSuccessful();
    }

    public function test_the_app_host_sends_a_signed_out_visitor_from_the_home_page_to_sign_in(): void
    {
        $client = $this->split();

        $this->get($client, 'localhost', '/');

        self::assertResponseRedirects('/login');
    }

    /** @return iterable<string, array{string}> */
    public static function legalPaths(): iterable
    {
        yield 'privacy' => ['/privacy'];
        yield 'terms' => ['/terms'];
        yield 'ai policy' => ['/ai-policy'];
    }

    #[DataProvider('legalPaths')]
    public function test_the_app_host_redirects_a_legal_page_to_the_site_host(string $path): void
    {
        $client = $this->split();

        $this->get($client, 'localhost', $path);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseHeaderSame('Location', 'https://'.self::SITE.$path);
    }

    public function test_the_sitemap_names_the_site_origin(): void
    {
        $client = $this->split();

        $this->get($client, self::SITE, '/sitemap.xml');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<loc>https://'.self::SITE.'/</loc>', $body);
        self::assertStringNotContainsString('localhost', $body);
    }

    public function test_the_footer_links_of_the_app_host_name_the_site_origin(): void
    {
        $client = $this->split();

        $this->get($client, 'localhost', '/login');

        self::assertSelectorExists('a[href="https://'.self::SITE.'/privacy"]');
    }

    public function test_the_waitlist_page_on_the_site_host_sends_open_sign_up_to_the_app_host(): void
    {
        $client = $this->split();

        $this->get($client, self::SITE, '/waitlist');

        self::assertResponseRedirects('http://localhost/register');
    }

    public function test_the_home_page_of_the_site_host_reads_no_cookie_from_a_signed_in_browser(): void
    {
        $client = $this->split();

        $this->get($client, self::SITE, '/', ['HTTP_COOKIE' => 'PHPSESSID=abc']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home');
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_an_instance_with_no_site_host_sends_a_signed_out_visitor_to_sign_in(): void
    {
        $this->setSiteHost('');
        $client = static::createClient();
        InstalledInstance::ensure(static::getContainer());

        $this->get($client, 'localhost', '/');

        self::assertResponseRedirects('/login');
        $this->get($client, 'localhost', '/privacy');
        self::assertResponseIsSuccessful();
    }

    public function test_one_host_for_both_roles_serves_the_home_page_and_the_app(): void
    {
        $this->setSiteHost('localhost');
        $client = static::createClient();
        InstalledInstance::ensure(static::getContainer());

        $this->get($client, 'localhost', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home');

        $this->get($client, 'localhost', '/login');
        self::assertResponseIsSuccessful();

        $this->get($client, 'localhost', '/privacy');
        self::assertResponseIsSuccessful();
    }

    private function setSiteHost(string $host): void
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $host;
    }
}
