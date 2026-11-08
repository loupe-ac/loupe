<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Preview\PreviewRenderer;
use App\Module\Insights\Preview\PublicPreviewEntries;
use App\Tests\Module\Insights\PublicPreviewScenario;
use App\Tests\Support\InstalledInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The preview tags of the share page. The test app host is `localhost`. */
final class ShowPublicLinkTagsTest extends WebTestCase
{
    use PublicPreviewScenario;

    private const string PNG = "\x89PNG\r\n\x1a\n";

    /** @return iterable<string, array{bool}> */
    public static function legends(): iterable
    {
        yield 'with the legend' => [true];
        yield 'without the legend' => [false];
    }

    #[DataProvider('legends')]
    public function test_a_graph_page_prints_its_own_preview_tags_and_the_image_address_carries_the_version_of_the_image(bool $legend): void
    {
        $client = $this->client();
        $link = $this->sharedCostGraph('tags-graph');
        $this->graphOf($link)->showLegend = $legend;
        $this->em()->flush();
        $project = $link->project->name;

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        self::assertSame('Cost · '.$project, $this->meta($client, 'og:title'));
        self::assertStringNotContainsString('Loupe', $this->meta($client, 'og:title'));
        $description = $this->meta($client, 'og:description');
        self::assertSame('Per run · Sum · 30 days · Per day · Line', $description);
        self::assertStringStartsWith($description, trim($client->getCrawler()->filter('.lp-graph-tile__settings')->text()));
        self::assertSame($description, (string) $client->getCrawler()->filter('meta[name="description"]')->attr('content'));
        self::assertSame('Preview image of Cost from the project '.$project, $this->meta($client, 'og:image:alt'));
        $image = $this->meta($client, 'og:image');
        self::assertSame(1, preg_match('#^http://localhost/s/'.$link->code.'/preview\.png\?v=([0-9a-f]{16})$#', $image, $matches), $image);

        $entries = self::getContainer()->get(PublicPreviewEntries::class);
        self::assertInstanceOf(PublicPreviewEntries::class, $entries);
        $kept = self::PNG.'kept-for-'.$matches[1];
        $entries->keep($link->code, $matches[1], $kept);
        $client->request(Request::METHOD_GET, '/s/'.$link->code.'/preview.png?v='.$matches[1]);
        self::assertResponseIsSuccessful();
        self::assertSame($kept, $client->getResponse()->getContent(), 'The image address serves the PNG kept for the version of the page.');
    }

    public function test_the_legend_changes_the_version_of_the_address(): void
    {
        $client = $this->client();
        $link = $this->sharedCostGraph('tags-legend');
        $versions = [];
        foreach ([true, false] as $legend) {
            $this->graphOf($link)->showLegend = $legend;
            $this->em()->flush();
            $client->request(Request::METHOD_GET, '/s/'.$link->code);
            $versions[] = $this->meta($client, 'og:image');
        }

        self::assertNotSame($versions[0], $versions[1]);
    }

    public function test_a_broken_graph_still_prints_tags_with_the_broken_version(): void
    {
        $client = $this->client();
        $link = $this->sharedCostGraph('tags-broken', withData: false);
        $this->graphOf($link)->metric = 'bucket-time:gone';
        $this->em()->flush();

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        self::assertStringEndsWith('/preview.png?v=broken', $this->meta($client, 'og:image'));
    }

    public function test_an_experiment_page_uses_its_note_as_the_description(): void
    {
        $client = $this->client();
        $link = $this->sharedExperiment('tags-note', "A **fair** test of two models.\n\nRead the table.");

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        self::assertSame('A fair test of two models. Read the table.', $this->meta($client, 'og:description'));
        self::assertSame('trial · '.$link->project->name, $this->meta($client, 'og:title'));
        self::assertMatchesRegularExpression('#^http://localhost/s/'.$link->code.'/preview\.png\?v=[0-9a-f]{16}$#', $this->meta($client, 'og:image'));
    }

    public function test_an_experiment_page_with_no_note_still_prints_a_description(): void
    {
        $client = $this->client();
        $link = $this->sharedExperiment('tags-nonote', null);

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        self::assertNotSame('', $this->meta($client, 'og:description'));
    }

    public function test_a_hostile_name_stays_inside_the_attribute(): void
    {
        $client = $this->client();
        $link = $this->sharedCostGraph('tags-hostile');
        $this->graphOf($link)->name = 'A "quoted" <b>name</b>';
        $this->em()->flush();

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        self::assertSame('A "quoted" <b>name</b> · '.$link->project->name, $this->meta($client, 'og:title'));
        self::assertStringNotContainsString('<b>name</b> ·', (string) $client->getResponse()->getContent());
    }

    private function graphOf(PublicLink $link): InsightsSavedGraph
    {
        return $link->savedGraph ?? throw new \LogicException('The link shares a graph.');
    }

    private function sharedExperiment(string $name, ?string $note): PublicLink
    {
        $project = $this->scenarioProject($name);
        $this->seedExperiment($project, 'trial');
        $link = PublicLink::forExperiment($project, 'trial', $note);
        $this->em()->persist($link);
        $this->em()->flush();

        return $link;
    }

    private function meta(KernelBrowser $client, string $property): string
    {
        return (string) $client->getCrawler()->filter('meta[property="'.$property.'"]')->attr('content');
    }

    private function client(): KernelBrowser
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = '';
        $client = static::createClient();
        $client->disableReboot();
        InstalledInstance::ensure(static::getContainer());
        static::getContainer()->set(PreviewRenderer::class, new PreviewRenderer(__DIR__.'/../Preview/fake-rsvg-convert'));

        return $client;
    }
}
