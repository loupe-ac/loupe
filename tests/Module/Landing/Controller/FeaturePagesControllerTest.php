<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Tests\Support\InstalledInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The test app host is `localhost`. */
final class FeaturePagesControllerTest extends WebTestCase
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
        $this->setSiteHost($this->previousSiteHost);
        parent::tearDown();
    }

    public function test_the_list_names_each_feature_and_needs_no_session(): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(Request::METHOD_GET, '/features');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Features');
        self::assertCount(8, $client->getCrawler()->filter('a[href^="/features/"]'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function features(): iterable
    {
        yield 'board' => ['board', 'See where every card stands.'];
        yield 'workflow rules' => ['workflow-rules', 'Cards move on their own, and stop where you decide.'];
        yield 'interactive sessions' => ['interactive-sessions', 'Some cards need you at the keyboard.'];
        yield 'document review' => ['document-review', 'Review the plan before the code exists.'];
        yield 'inbox' => ['inbox', 'Your agents have questions. Answer them in one place.'];
        yield 'agent review' => ['agent-review', 'A second agent reads every change first.'];
        yield 'live review' => ['live-review', 'Point at the problem, and the note lands on the card.'];
        yield 'analytics' => ['analytics', 'Know what each finished card costs.'];
    }

    #[DataProvider('features')]
    public function test_a_feature_page_shows_its_title(string $slug, string $title): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(Request::METHOD_GET, '/features/'.$slug);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $title);
    }

    /** @return iterable<string, array{string}> */
    public static function paths(): iterable
    {
        yield 'list' => ['/features'];
        yield 'page' => ['/features/board'];
    }

    #[DataProvider('paths')]
    public function test_an_instance_with_no_site_host_answers_404(string $path): void
    {
        $client = $this->clientWithSiteHost('');

        $client->request(Request::METHOD_GET, $path);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownSlugs(): iterable
    {
        yield 'unknown' => ['/features/no-such-feature'];
        yield 'upper case' => ['/features/BOARD'];
        yield 'nested' => ['/features/board/extra'];
    }

    #[DataProvider('unknownSlugs')]
    public function test_an_unknown_slug_answers_404(string $path): void
    {
        $client = $this->clientWithSiteHost('localhost');

        $client->request(Request::METHOD_GET, $path);

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('paths')]
    public function test_a_split_instance_serves_the_site_host_without_a_session(string $path): void
    {
        $client = $this->clientWithSiteHost('loupe.example');

        $client->request(Request::METHOD_GET, $path, server: ['HTTP_HOST' => 'loupe.example']);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    #[DataProvider('paths')]
    public function test_a_split_instance_sends_the_app_host_to_the_site_host(string $path): void
    {
        $client = $this->clientWithSiteHost('loupe.example');

        $client->request(Request::METHOD_GET, $path, server: ['HTTP_HOST' => 'localhost']);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseHeaderSame('Location', 'https://loupe.example'.$path);
    }

    private function clientWithSiteHost(string $host): KernelBrowser
    {
        $this->setSiteHost($host);
        $client = static::createClient();
        $client->disableReboot();
        InstalledInstance::ensure(static::getContainer());

        return $client;
    }

    private function setSiteHost(string $host): void
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $host;
    }
}
