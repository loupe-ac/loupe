<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Tests\Support\InstalledInstance;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The test app host is `localhost`. */
final class FaqPageControllerTest extends WebTestCase
{
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

    public function test_the_page_needs_no_session_and_links_each_question(): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(Request::METHOD_GET, '/faq');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Questions people ask about Loupe');
        self::assertSelectorExists('.lp-legal__body h3#q1');
        self::assertSelectorExists('.lp-legal__body h2#how-we-counted');
        self::assertSelectorExists('.lp-legal__body a[href="#source-1"][data-turbo="false"]');
        self::assertSelectorExists('.lp-legal__actions a[href="/waitlist"]');
    }

    public function test_an_instance_with_no_site_host_answers_404(): void
    {
        $client = $this->clientWithSiteHost('');

        $client->request(Request::METHOD_GET, '/faq');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_split_instance_sends_the_app_host_to_the_site_host_and_serves_the_site_host_without_a_session(): void
    {
        $client = $this->clientWithSiteHost('loupe.example');

        $client->request(Request::METHOD_GET, '/faq', server: ['HTTP_HOST' => 'localhost']);
        self::assertResponseStatusCodeSame(301);
        self::assertResponseHeaderSame('Location', 'https://loupe.example/faq');

        $client->request(Request::METHOD_GET, '/faq', server: ['HTTP_HOST' => 'loupe.example']);
        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_a_legal_page_footer_links_the_faq_with_a_site_host(): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(Request::METHOD_GET, '/privacy');

        self::assertSelectorExists('.lp-home-footer a[href="/faq"]');
    }

    public function test_a_legal_page_footer_links_no_faq_without_a_site_host(): void
    {
        $client = $this->clientWithSiteHost('');
        $client->request(Request::METHOD_GET, '/privacy');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.lp-home-footer a[href*="/faq"]');
    }

    private function clientWithSiteHost(string $host): KernelBrowser
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $host;
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->disableReboot();
        InstalledInstance::ensure(static::getContainer());

        return $client;
    }
}
