<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The test app host is `localhost`. The split site host is `loupe.example`. */
final class ShowSiteAboutControllerTest extends WebTestCase
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

    public function test_the_site_host_shows_the_page_with_the_full_closing_block_and_no_session(): void
    {
        $client = $this->clientWithSiteHost('loupe.example');

        $client->request(Request::METHOD_GET, '/about', server: ['HTTP_HOST' => 'loupe.example']);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        self::assertSelectorTextContains('h1', 'Who builds Loupe, and why');
        self::assertSelectorExists('.lp-legal__body a[href="https://github.com/ubermuda"]');
        self::assertSelectorNotExists('.lp-about-card');

        $crawler = $client->getCrawler();
        $heading = $crawler->filter('.lp-legal__body h2')->last();
        self::assertSame('Try Loupe', trim($heading->text()));
        self::assertSelectorTextContains('.lp-legal__body section', 'Run Loupe on your own server, or join the waitlist.');
        self::assertCount(2, $crawler->filter('.lp-legal__actions a[data-analytics-button-param="about"]'));
        self::assertSelectorTextContains('.lp-legal__actions', 'Join the waitlist');
        self::assertSelectorTextContains('.lp-legal__actions', 'Self-host Loupe');
        self::assertSame('https://loupe.example/waitlist', $crawler->filter('.lp-legal__actions a')->first()->attr('href'));
    }

    public function test_the_top_bar_has_no_about_link_and_the_footer_has_one(): void
    {
        $client = $this->clientWithSiteHost('loupe.example');

        $client->request(Request::METHOD_GET, '/about', server: ['HTTP_HOST' => 'loupe.example']);

        self::assertSelectorExists('.lp-home-footer a[href="https://loupe.example/about"]');
        self::assertSelectorNotExists('header a[href$="/about"]');
    }

    public function test_the_app_host_of_a_split_instance_keeps_the_app_about_page_without_a_redirect(): void
    {
        $client = $this->clientWithSiteHost('loupe.example');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Aboutviewer', 'about-site-viewer@example.com');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->password = 'hashed-password-placeholder';
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, '/about', server: ['HTTP_HOST' => 'localhost']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="https://github.com/loupe-ac/loupe"]');
        self::assertSelectorExists('[data-testid="app-version"]');
        self::assertSelectorNotExists('.lp-legal__body');
    }

    public function test_an_instance_with_no_site_host_shows_the_app_page_and_no_footer_link(): void
    {
        $client = $this->clientWithSiteHost('');

        $client->request(Request::METHOD_GET, '/about');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="https://github.com/loupe-ac/loupe"]');
        self::assertSelectorNotExists('.lp-legal__body');

        $client->request(Request::METHOD_GET, '/privacy');
        self::assertSelectorNotExists('.lp-home-footer a[href$="/about"]');
    }

    public function test_an_instance_whose_site_host_equals_the_app_host_shows_the_app_page(): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(Request::METHOD_GET, '/about');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.lp-legal__body');

        $client->request(Request::METHOD_GET, '/privacy');
        self::assertSelectorNotExists('.lp-home-footer a[href$="/about"]');
    }

    public function test_the_sitemap_and_llms_list_the_page_on_a_split_instance(): void
    {
        $client = $this->clientWithSiteHost('loupe.example');
        $client->request(Request::METHOD_GET, '/sitemap.xml', server: ['HTTP_HOST' => 'loupe.example']);
        self::assertStringContainsString('<loc>https://loupe.example/about</loc>', (string) $client->getResponse()->getContent());
        $client->request(Request::METHOD_GET, '/llms.txt', server: ['HTTP_HOST' => 'loupe.example']);
        self::assertStringContainsString("\n## About\n\n- [Who builds Loupe, and why](https://loupe.example/about): ", (string) $client->getResponse()->getContent());
    }

    public function test_the_sitemap_and_llms_omit_the_page_on_an_unsplit_instance(): void
    {
        $client = $this->clientWithSiteHost('localhost');
        $client->request(Request::METHOD_GET, '/sitemap.xml');
        self::assertStringNotContainsString('/about', (string) $client->getResponse()->getContent());
        $client->request(Request::METHOD_GET, '/llms.txt');
        self::assertStringNotContainsString('## About', (string) $client->getResponse()->getContent());
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
