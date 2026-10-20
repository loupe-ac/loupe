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
        yield 'unknown path' => ['/no-such-page'];
    }

    #[DataProvider('appPaths')]
    public function test_the_site_host_answers_an_app_path_with_a_plain_404(string $path): void
    {
        $client = $this->split();

        $this->get($client, self::SITE, $path, method: Request::METHOD_GET);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string, string}> */
    public static function wrongMethods(): iterable
    {
        yield 'webhook read' => [Request::METHOD_GET, '/webhooks/stripe'];
        yield 'webhook write' => [Request::METHOD_POST, '/webhooks/stripe'];
        yield 'app page written' => [Request::METHOD_POST, '/projects'];
        yield 'site page written' => [Request::METHOD_POST, '/privacy'];
    }

    #[DataProvider('wrongMethods')]
    public function test_the_site_host_answers_a_wrong_method_with_a_plain_404(string $method, string $path): void
    {
        $client = $this->split();

        $this->get($client, self::SITE, $path, method: $method);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_app_host_keeps_its_405(): void
    {
        $client = $this->split();

        $this->get($client, 'localhost', '/webhooks/stripe');

        self::assertResponseStatusCodeSame(405);
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

    public function test_the_waitlist_notify_form_posts_on_the_site_host_without_a_session(): void
    {
        $client = $this->split();
        $crawler = $client->request(Request::METHOD_GET, '/', server: ['HTTP_HOST' => self::SITE]);
        $form = $crawler->filter('turbo-frame#waitlist-notify form')->form(['waitlist_join_form[email]' => 'site-host-notify@example.com']);

        $client->submit($form, [], ['HTTP_HOST' => self::SITE, 'HTTP_ORIGIN' => 'http://'.self::SITE]);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/waitlist/notify?joined=1', (string) $client->getResponse()->headers->get('Location'));
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_the_waitlist_notify_form_refuses_a_bad_token_on_the_site_host(): void
    {
        $client = $this->split();
        $crawler = $client->request(Request::METHOD_GET, '/', server: ['HTTP_HOST' => self::SITE]);
        $form = $crawler->filter('turbo-frame#waitlist-notify form')->form([
            'waitlist_join_form[email]' => 'site-host-bad-token@example.com',
            'waitlist_join_form[_token]' => 'forged',
        ]);

        $client->submit($form, [], ['HTTP_HOST' => self::SITE, 'HTTP_ORIGIN' => 'http://'.self::SITE]);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_the_waitlist_notify_form_refuses_a_foreign_origin_on_the_site_host(): void
    {
        $client = $this->split();
        $crawler = $client->request(Request::METHOD_GET, '/', server: ['HTTP_HOST' => self::SITE]);
        $form = $crawler->filter('turbo-frame#waitlist-notify form')->form(['waitlist_join_form[email]' => 'site-host-origin@example.com']);

        $client->submit($form, [], ['HTTP_HOST' => self::SITE, 'HTTP_ORIGIN' => 'http://evil.example', 'HTTP_REFERER' => 'http://evil.example/']);

        self::assertResponseStatusCodeSame(422);
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
