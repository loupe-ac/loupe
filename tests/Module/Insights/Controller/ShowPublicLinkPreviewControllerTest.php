<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Preview\PreviewRenderer;
use App\Module\Insights\Preview\PublicPreviewEntries;
use App\Module\Insights\Preview\PublicPreviewSubject;
use App\Module\Insights\Preview\PublicPreviewSubjects;
use App\Module\Insights\Preview\PublicPreviewVersion;
use App\Tests\Module\Insights\Preview\ScriptedSubjects;
use App\Tests\Module\Insights\PublicPreviewScenario;
use App\Tests\Support\InstalledInstance;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ShowPublicLinkPreviewControllerTest extends WebTestCase
{
    use PublicPreviewScenario;

    private const string PNG = "\x89PNG\r\n\x1a\n";

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

    public function test_a_kept_png_of_the_current_version_is_served_with_no_store_so_a_hidden_link_leaves_no_cached_image(): void
    {
        $client = $this->client();
        $link = $this->sharedCostGraph('image-kept');
        $kept = self::PNG.'kept-bytes';
        $this->entries()->keep($link->code, $this->currentVersion($link), $kept);

        $client->request(Request::METHOD_GET, $this->url($link).'?v=anything');

        self::assertResponseIsSuccessful();
        self::assertSame($kept, $client->getResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringNotContainsString('max-age', $cacheControl);
    }

    public function test_the_image_sets_no_cookie_and_starts_no_session(): void
    {
        $client = $this->client();
        $link = $this->sharedCostGraph('image-cookie');

        $client->request(Request::METHOD_GET, $this->url($link));

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        self::assertCount(0, $client->getCookieJar()->all());
    }

    public function test_a_png_of_an_older_version_is_drawn_again_inline_and_kept(): void
    {
        $client = $this->client();
        $link = $this->sharedCostGraph('image-inline');
        $this->entries()->keep($link->code, 'older-version', self::PNG.'older');

        $client->request(Request::METHOD_GET, $this->url($link));

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith(self::PNG, $content);
        self::assertStringNotContainsString('older', $content);
        $entry = $this->entries()->entryFor($link->code);
        self::assertNotNull($entry);
        self::assertSame($this->currentVersion($link), $entry->version);
        self::assertSame($content, $this->entries()->png($entry));
    }

    public function test_no_kept_png_draws_inline(): void
    {
        $client = $this->client();
        $link = $this->sharedCostGraph('image-none');
        self::assertNull($this->entries()->entryFor($link->code));

        $client->request(Request::METHOD_GET, $this->url($link));

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->entries()->entryFor($link->code));
    }

    public function test_a_broken_graph_and_an_empty_graph_get_an_image_of_their_own(): void
    {
        $client = $this->client();
        $broken = PublicLink::forGraph($this->seedSavedGraph($this->scenarioProject('image-broken'), 'Tests', 0));
        $this->em()->persist($broken);
        $empty = $this->sharedCostGraph('image-empty', withData: false);
        $this->em()->flush();

        $client->request(Request::METHOD_GET, $this->url($broken));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertSame(PublicPreviewVersion::BROKEN, $this->entries()->entryFor($broken->code)?->version);

        $client->request(Request::METHOD_GET, $this->url($empty));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertSame($this->currentVersion($empty), $this->entries()->entryFor($empty->code)?->version);
        self::assertNotSame(PublicPreviewVersion::BROKEN, $this->entries()->entryFor($empty->code)?->version);
    }

    public function test_every_reason_to_hide_the_link_gives_404_before_any_cache_read(): void
    {
        $client = $this->client();
        $off = $this->sharedCostGraph('image-off');
        $this->entries()->keep($off->code, $this->currentVersion($off), self::PNG.'kept-bytes');
        $off->enabled = false;
        $removed = $this->sharedCostGraph('image-removed');
        $this->entries()->keep($removed->code, $this->currentVersion($removed), self::PNG.'kept-bytes');
        $removedGraph = $removed->savedGraph ?? throw new \LogicException('A graph link has a graph.');
        $removedGraph->removedAt = new \DateTimeImmutable('-1 hour');
        $this->em()->flush();

        foreach ([$off->code, $removed->code, 'aaaaaaaaaaaa'] as $code) {
            $client->request(Request::METHOD_GET, '/s/'.$code.'/preview.png');
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('kept-bytes', (string) $client->getResponse()->getContent());
            self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        }
    }

    public function test_requests_above_the_limit_get_429(): void
    {
        $client = $this->client();
        $link = $this->sharedCostGraph('image-limit');
        static::getContainer()->set('limiter.public_link_image', new RateLimiterFactory(
            ['id' => 'public_link_image', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));

        $client->request(Request::METHOD_GET, $this->url($link));
        self::assertResponseIsSuccessful();
        $client->request(Request::METHOD_GET, $this->url($link));
        self::assertResponseIsSuccessful();
        $client->request(Request::METHOD_GET, $this->url($link));

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
    }

    public function test_a_result_that_never_settles_gets_the_generic_image_with_no_store_and_keeps_nothing(): void
    {
        $client = $this->client();
        $link = $this->sharedCostGraph('image-unsettled');
        $subject = static fn (string $version): PublicPreviewSubject => new PublicPreviewSubject($version, null);
        static::getContainer()->set(PublicPreviewSubjects::class, new ScriptedSubjects([$subject('0'), $subject('1'), $subject('2'), $subject('3'), $subject('4')]));

        $client->request(Request::METHOD_GET, $this->url($link));

        $this->assertGenericImage($client);
        self::assertNull($this->entries()->entryFor($link->code));
    }

    public function test_a_failed_tool_gets_the_generic_image_with_no_store(): void
    {
        $client = $this->client('no-such-render-tool');
        $link = $this->sharedCostGraph('image-failed');

        $client->request(Request::METHOD_GET, $this->url($link));

        $this->assertGenericImage($client);
        self::assertNull($this->entries()->entryFor($link->code));
    }

    public function test_an_experiment_link_draws_its_own_image_inline_and_keeps_it(): void
    {
        $client = $this->client();
        $project = $this->scenarioProject('image-experiment');
        $this->seedExperiment($project, 'trial');
        $link = PublicLink::forExperiment($project, 'trial');
        $this->em()->persist($link);
        $this->em()->flush();

        $client->request(Request::METHOD_GET, $this->url($link));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringStartsWith(self::PNG, (string) $client->getResponse()->getContent());
        $entry = $this->entries()->entryFor($link->code);
        self::assertNotNull($entry);
        self::assertSame($this->currentVersion($link), $entry->version);
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_an_experiment_link_with_no_report_gets_404(): void
    {
        $client = $this->client();
        $project = $this->scenarioProject('image-experiment-missing');
        $link = PublicLink::forExperiment($project, 'no-such-trial');
        $this->em()->persist($link);
        $this->em()->flush();

        $client->request(Request::METHOD_GET, $this->url($link));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_site_host_of_a_split_instance_serves_the_image(): void
    {
        $client = $this->client(siteHost: 'loupe.example');
        $link = $this->sharedCostGraph('image-site-host');

        $client->request(Request::METHOD_GET, $this->url($link), server: ['HTTP_HOST' => 'loupe.example']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    private function assertGenericImage(KernelBrowser $client): void
    {
        self::assertResponseRedirects();
        self::assertStringContainsString('/images/og/', (string) $client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    private function url(PublicLink $link): string
    {
        return '/s/'.$link->code.'/preview.png';
    }

    private function currentVersion(PublicLink $link): string
    {
        $subjects = self::getContainer()->get(PublicPreviewSubjects::class);
        self::assertInstanceOf(PublicPreviewSubjects::class, $subjects);

        return $subjects->read($link)->version ?? self::fail('The link has a graph.');
    }

    private function entries(): PublicPreviewEntries
    {
        $entries = self::getContainer()->get(PublicPreviewEntries::class);
        self::assertInstanceOf(PublicPreviewEntries::class, $entries);

        return $entries;
    }

    private function client(string $tool = __DIR__.'/../Preview/fake-rsvg-convert', string $siteHost = ''): KernelBrowser
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $siteHost;
        $client = static::createClient();
        $client->disableReboot();
        InstalledInstance::ensure(static::getContainer());
        static::getContainer()->set(PreviewRenderer::class, new PreviewRenderer($tool));

        return $client;
    }
}
