<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Module\Analytics\Twig\AnalyticsScript;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/** The test app host is `localhost`. The site host is `loupe.example`, so the two differ. */
final class SiteSignedInSignalTest extends WebTestCase
{
    private const string SITE = 'loupe.example';

    /** @var array<string, string> */
    private array $previous = [];

    #[\Override]
    protected function setUp(): void
    {
        foreach (['SITE_HOST', 'ANALYTICS_SCRIPT_URL', 'ANALYTICS_WEBSITE_ID', 'SITE_REVIEW_WIDGET_PROJECT', 'SITE_REVIEW_WIDGET_BACKEND', 'SITE_REVIEW_WIDGET_PREVIEW_TOKEN', 'SITE_REVIEW_WIDGET_PUBLIC'] as $name) {
            $this->previous[$name] = (string) ($_SERVER[$name] ?? '');
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            $this->setEnv($name, $value);
        }
        parent::tearDown();
    }

    private function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    private function client(?string $cookie): KernelBrowser
    {
        $this->setEnv('SITE_HOST', self::SITE);
        $client = static::createClient();
        InstalledInstance::ensure(static::getContainer());
        $this->setCookie($client, $cookie);

        return $client;
    }

    private function setCookie(KernelBrowser $client, ?string $value): void
    {
        $client->getCookieJar()->clear();
        if (null !== $value) {
            $client->getCookieJar()->set(new Cookie('loupe_signed_in', $value, domain: self::SITE));
        }
    }

    private function get(KernelBrowser $client, string $path): void
    {
        $client->request(Request::METHOD_GET, $path, server: ['HTTP_HOST' => self::SITE]);
    }

    private function withAnalytics(KernelBrowser $client): void
    {
        $this->setEnv('ANALYTICS_SCRIPT_URL', 'https://analytics.test/script.js');
        $this->setEnv('ANALYTICS_WEBSITE_ID', 'website-id');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(new FeatureFlag(name: AnalyticsScript::ENABLED_FLAG, type: FeatureFlagType::Bool, value: true));
        $em->flush();
        $client->getKernel()->shutdown();
    }

    private function withWidget(): void
    {
        $this->setEnv('SITE_REVIEW_WIDGET_PROJECT', 'project-handle');
        $this->setEnv('SITE_REVIEW_WIDGET_BACKEND', 'https://app.example');
        $this->setEnv('SITE_REVIEW_WIDGET_PUBLIC', '0');
        $this->setEnv('SITE_REVIEW_WIDGET_PREVIEW_TOKEN', 'secret-preview-token');
    }

    /** @return iterable<string, array{string}> */
    public static function signedInValues(): iterable
    {
        yield 'user' => ['1'];
        yield 'administrator' => ['admin'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signedInValues')]
    public function test_the_home_page_shows_go_to_app_to_a_signed_in_browser_and_stays_private(string $value): void
    {
        $client = $this->client($value);

        $this->get($client, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home');
        self::assertSelectorExists('a[data-site-chrome="go-to-app"]:not([hidden])');
        self::assertSelectorNotExists('[data-site-chrome="signed-out"]:not([hidden])');
        self::assertSelectorCount(2, '[data-site-chrome="signed-out"][hidden]');
        $response = $client->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function test_the_home_page_hides_go_to_app_from_a_visitor(): void
    {
        $client = $this->client(null);

        $this->get($client, '/');

        self::assertSelectorExists('a[data-site-chrome="go-to-app"][hidden]');
        self::assertSelectorCount(2, '[data-site-chrome="signed-out"]:not([hidden])');
    }

    public function test_the_home_page_shows_a_visitor_with_no_cookie(): void
    {
        $client = $this->client(null);

        $this->get($client, '/');

        self::assertResponseIsSuccessful();
    }

    public function test_an_unknown_cookie_value_is_ignored(): void
    {
        $client = $this->client('yes');

        $this->get($client, '/');

        self::assertResponseIsSuccessful();
    }

    public function test_a_legal_page_shows_go_to_app_to_a_signed_in_browser(): void
    {
        $client = $this->client('1');

        $this->get($client, '/privacy');

        self::assertSelectorExists('a[data-site-chrome="go-to-app"]:not([hidden])');
        self::assertSelectorNotExists('[data-site-chrome="signed-out"]:not([hidden])');
        self::assertSelectorExists('[data-site-chrome="signed-out"][hidden]');
    }

    public function test_a_legal_page_hides_go_to_app_from_a_visitor(): void
    {
        $client = $this->client(null);

        $this->get($client, '/privacy');

        self::assertSelectorExists('a[data-site-chrome="go-to-app"][hidden]');
        self::assertSelectorNotExists('[data-site-chrome="signed-out"][hidden]');
    }

    public function test_a_signed_in_browser_loads_no_analytics_tag_on_a_legal_page(): void
    {
        $client = $this->client('1');
        $this->withAnalytics($client);

        $this->get($client, '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('title');
        self::assertSelectorNotExists('script[data-website-id]');
    }

    public function test_a_visitor_loads_the_analytics_tag_on_a_legal_page(): void
    {
        $client = $this->client(null);
        $this->withAnalytics($client);

        $this->get($client, '/privacy');

        self::assertSelectorExists('script[data-website-id="website-id"]');
    }

    public function test_the_widget_embed_shows_for_the_admin_value_alone(): void
    {
        $client = $this->client('admin');
        $this->withWidget();
        $client->getKernel()->shutdown();
        $this->get($client, '/privacy');
        self::assertSelectorExists('script[src="https://app.example/live-review/widget.js"]');
        self::assertStringNotContainsString('secret-preview-token', (string) $client->getResponse()->getContent());
        self::assertSelectorNotExists('script[data-preview-token]');

        $this->setCookie($client, '1');
        $this->get($client, '/privacy');
        self::assertSelectorNotExists('script[src$="widget.js"]');

        $this->setCookie($client, null);
        $this->get($client, '/privacy');
        self::assertSelectorNotExists('script[src$="widget.js"]');
    }

    public function test_the_docs_script_is_cacheable_and_ignores_the_cookie(): void
    {
        $client = $this->client(null);
        $this->get($client, '/site/docs.js');
        self::assertResponseIsSuccessful();
        $anonymous = $client->getResponse();
        self::assertStringContainsString('text/javascript', (string) $anonymous->headers->get('Content-Type'));
        self::assertTrue($anonymous->headers->hasCacheControlDirective('public'));
        self::assertSame('300', $anonymous->headers->getCacheControlDirective('max-age'));
        self::assertFalse($anonymous->headers->has('Vary'));
        self::assertFalse($anonymous->headers->has('Set-Cookie'));
        $anonymousBody = (string) $anonymous->getContent();

        $this->setCookie($client, 'admin');
        $this->get($client, '/site/docs.js');
        $signed = $client->getResponse();

        self::assertFalse($signed->headers->has('Vary'));
        self::assertSame($anonymousBody, (string) $signed->getContent());
        self::assertStringContainsString('loupe_signed_in', $anonymousBody);
        self::assertStringContainsString('"appOrigin":"http:\/\/localhost"', $anonymousBody);
        self::assertStringContainsString('element.shadowRoot', $anonymousBody);
    }

    public function test_the_docs_script_carries_the_tag_and_the_widget_but_not_the_preview_token(): void
    {
        $client = $this->client(null);
        $this->withAnalytics($client);
        $this->withWidget();
        $client->getKernel()->shutdown();

        $this->get($client, '/site/docs.js');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('analytics.test', $body);
        self::assertStringContainsString('website-id', $body);
        self::assertStringContainsString('widget.js', $body);
        self::assertStringContainsString('project-handle', $body);
        self::assertStringNotContainsString('secret-preview-token', $body);
    }

    public function test_the_docs_script_answers_on_the_app_host_without_a_session(): void
    {
        $client = $this->client(null);

        $client->request(Request::METHOD_GET, '/site/docs.js');

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        self::assertFalse($client->getResponse()->headers->has('Vary'));
    }
}
