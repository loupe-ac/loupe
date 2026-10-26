<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Command;

use App\Module\Landing\Command\ShowSitemapHandler;
use App\Module\Landing\Service\PublicPages;
use App\Site\SiteHost;
use App\Tests\Support\ComparisonFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ShowSitemapHandlerTest extends KernelTestCase
{
    private string $docs = '';

    private string $previousSiteHost = '';

    private string $fixtures = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->docs = sys_get_temp_dir().'/sitemap-docs-'.bin2hex(random_bytes(4));
        $this->previousSiteHost = (string) ($_SERVER['SITE_HOST'] ?? '');
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = 'site.example';
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->docs);
        if ('' !== $this->fixtures) {
            ComparisonFixtures::remove($this->fixtures);
        }
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $this->previousSiteHost;
        parent::tearDown();
    }

    public function test_it_lists_the_docs_pages_on_the_site_origin(): void
    {
        $this->writeSitemaps(
            ['https://loupe.ac/docs/sitemap-0.xml', 'https://loupe.ac/docs/sitemap-1.xml'],
            ['sitemap-0.xml' => ['https://loupe.ac/docs/', 'https://loupe.ac/docs/using/mcp/'], 'sitemap-1.xml' => ['https://loupe.ac/docs/changelog/?x=1']],
        );

        $locations = $this->locations();

        self::assertContains('https://site.example/docs/', $locations);
        self::assertContains('https://site.example/docs/using/mcp/', $locations);
        self::assertContains('https://site.example/docs/changelog/', $locations);
        self::assertContains('https://site.example/privacy', $locations);
        self::assertSame($locations, array_values(array_unique($locations)));
        foreach ($locations as $location) {
            self::assertStringNotContainsString('loupe.ac', $location);
        }
    }

    public function test_it_lists_the_comparison_list_and_one_url_per_page_and_no_bare_slash_entry(): void
    {
        $locations = $this->locations();

        self::assertContains('https://site.example/compare', $locations);
        self::assertSame(1, \count(array_filter($locations, static fn (string $location): bool => str_starts_with($location, 'https://site.example/compare/'))));
        self::assertContains('https://site.example/compare/'.ComparisonFixtures::SLUG, $locations);
        self::assertNotContains('https://site.example/compare/', $locations);
    }

    public function test_it_lists_no_comparison_url_without_a_site_host(): void
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = '';

        $locations = $this->locations();

        self::assertSame([], array_filter($locations, static fn (string $location): bool => str_contains($location, '/compare')));
    }

    public function test_a_missing_docs_folder_lists_the_site_pages_alone(): void
    {
        $locations = $this->locations();

        self::assertContains('https://site.example/privacy', $locations);
        self::assertSame([], array_filter($locations, static fn (string $location): bool => str_contains($location, '/docs/')));
    }

    public function test_a_broken_sitemap_index_lists_the_site_pages_alone(): void
    {
        new Filesystem()->mkdir($this->docs);
        file_put_contents($this->docs.'/sitemap-index.xml', '<sitemapindex><sitemap><loc>');

        self::assertContains('https://site.example/privacy', $this->locations());
    }

    public function test_a_sitemap_file_cannot_name_a_path_outside_the_docs_folder(): void
    {
        $this->writeSitemaps(['https://loupe.ac/docs/../../etc/passwd'], []);

        self::assertNotContains('https://site.example/docs/', $this->locations());
    }

    /**
     * @param list<string>                $index
     * @param array<string, list<string>> $files
     */
    private function writeSitemaps(array $index, array $files): void
    {
        new Filesystem()->mkdir($this->docs);
        $entries = implode('', array_map(static fn (string $loc): string => '<sitemap><loc>'.$loc.'</loc></sitemap>', $index));
        file_put_contents($this->docs.'/sitemap-index.xml', '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$entries.'</sitemapindex>');
        foreach ($files as $name => $urls) {
            $items = implode('', array_map(static fn (string $loc): string => '<url><loc>'.$loc.'</loc></url>', $urls));
            file_put_contents($this->docs.'/'.$name, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$items.'</urlset>');
        }
    }

    /** @return list<string> */
    private function locations(): array
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        if ('' !== $this->fixtures) {
            ComparisonFixtures::remove($this->fixtures);
        }
        $this->fixtures = ComparisonFixtures::install(static::getContainer(), new SiteHost((string) $_SERVER['SITE_HOST'], 'http://localhost'), 'http://localhost');
        $publicPages = static::getContainer()->get(PublicPages::class);
        self::assertInstanceOf(PublicPages::class, $publicPages);
        $xml = simplexml_load_string((new ShowSitemapHandler($publicPages, $this->docs))());
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        return array_values(array_map(strval(...), $xml->xpath('//*[local-name()="loc"]') ?: []));
    }
}
