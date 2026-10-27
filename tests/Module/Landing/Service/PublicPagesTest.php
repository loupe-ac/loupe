<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Service;

use App\Module\Account\Service\RegistrationGate;
use App\Module\Landing\Service\ComparisonPages;
use App\Module\Landing\Service\PublicPages;
use App\Site\SiteHost;
use App\Tests\Support\ComparisonFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicPagesTest extends KernelTestCase
{
    private string $fixtures = '';

    #[\Override]
    protected function tearDown(): void
    {
        if ('' !== $this->fixtures) {
            ComparisonFixtures::remove($this->fixtures);
        }
        parent::tearDown();
    }

    public function test_paths_hold_the_list_and_the_path_of_each_page(): void
    {
        $paths = $this->publicPages('site.example')->paths();

        self::assertContains('/compare', $paths);
        self::assertContains('/compare/'.ComparisonFixtures::SLUG, $paths);
    }

    public function test_has_comparisons_is_false_with_no_site_host(): void
    {
        self::assertFalse($this->publicPages('')->hasComparisons());
    }

    public function test_has_comparisons_is_true_with_a_site_host_and_a_page(): void
    {
        self::assertTrue($this->publicPages('site.example')->hasComparisons());
    }

    public function test_has_comparisons_is_false_with_a_site_host_and_no_page(): void
    {
        $empty = sys_get_temp_dir().'/compare-empty-'.bin2hex(random_bytes(4));
        $siteHost = new SiteHost('site.example', 'http://localhost');

        $pages = $this->publicPages('site.example', new ComparisonPages($empty, $siteHost, 'http://localhost'));

        self::assertFalse($pages->hasComparisons());
    }

    public function test_a_comparison_page_and_the_list_are_public_and_site_routes(): void
    {
        $pages = $this->publicPages('site.example');

        foreach (['app_compare', 'app_compare_list'] as $route) {
            self::assertTrue($pages->isPublic($route));
            self::assertTrue($pages->isSiteRoute($route));
            self::assertTrue($pages->isSiteOnlyRoute($route));
        }
        self::assertFalse($pages->isSiteOnlyRoute('app_home'));
    }

    public function test_the_sitemap_names_the_list_and_each_page_with_a_site_host_only(): void
    {
        $with = $this->publicPages('site.example')->sitemapUrls();
        $without = $this->publicPages('')->sitemapUrls();

        self::assertContains('https://site.example/compare', $with);
        self::assertContains('https://site.example/compare/'.ComparisonFixtures::SLUG, $with);
        self::assertSame([], array_filter($without, static fn (string $url): bool => str_contains($url, '/compare')));
    }

    public function test_the_faq_is_a_public_site_only_route_and_is_in_the_sitemap_with_a_site_host_only(): void
    {
        $pages = $this->publicPages('site.example');

        self::assertTrue($pages->isPublic('app_faq'));
        self::assertTrue($pages->isSiteRoute('app_faq'));
        self::assertTrue($pages->isSiteOnlyRoute('app_faq'));
        self::assertContains('https://site.example/faq', $pages->sitemapUrls());
        self::assertNotContains('/faq', array_map(static fn (string $url): string => (string) parse_url($url, \PHP_URL_PATH), $this->publicPages('')->sitemapUrls()));
        self::assertTrue($pages->hasFaq());
        self::assertFalse($this->publicPages('')->hasFaq());
    }

    private function publicPages(string $host, ?ComparisonPages $comparisons = null): PublicPages
    {
        self::bootKernel();
        $siteHost = new SiteHost($host, 'http://localhost');
        $container = static::getContainer();
        if (null === $comparisons) {
            $this->fixtures = ComparisonFixtures::install($container, $siteHost, 'http://localhost');
            $comparisons = $container->get(ComparisonPages::class);
            self::assertInstanceOf(ComparisonPages::class, $comparisons);
        }
        $urlGenerator = $container->get(UrlGeneratorInterface::class);
        $gate = $container->get(RegistrationGate::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urlGenerator);
        self::assertInstanceOf(RegistrationGate::class, $gate);

        return new PublicPages($urlGenerator, $siteHost, $gate, $comparisons, 'http://localhost', '');
    }
}
