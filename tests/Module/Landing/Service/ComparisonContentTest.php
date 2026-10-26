<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Service;

use App\Module\Landing\Service\ComparisonPages;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Every shipped content file parses, so a bad file fails here and not in production. */
final class ComparisonContentTest extends KernelTestCase
{
    public function test_every_content_file_has_every_field_a_valid_date_and_a_clean_slug(): void
    {
        $pages = static::getContainer()->get(ComparisonPages::class);
        self::assertInstanceOf(ComparisonPages::class, $pages);
        $directory = \dirname(__DIR__, 4).'/src/Module/Landing/Resources/compare';
        $files = glob($directory.'/*.md');
        self::assertIsArray($files);

        $parsed = $pages->all();

        self::assertCount(\count($files), $parsed, 'Every content file becomes a page.');
        foreach ($parsed as $page) {
            self::assertMatchesRegularExpression('/^'.ComparisonPages::SLUG_PATTERN.'$/', $page->slug);
            self::assertFileExists($directory.'/'.$page->slug.'.md');
            self::assertNotSame('', $page->competitor);
            self::assertNotSame('', $page->title);
            self::assertNotSame('', $page->description);
            self::assertNotSame('', $page->competitorVersion);
            self::assertNotSame('', trim($page->html));
            self::assertLessThanOrEqual(new \DateTimeImmutable('tomorrow'), $page->comparedOn);
        }
    }

    public function test_every_page_links_its_sources_and_cites_them_beside_its_claims(): void
    {
        $pages = static::getContainer()->get(ComparisonPages::class);
        self::assertInstanceOf(ComparisonPages::class, $pages);

        foreach ($pages->all() as $page) {
            self::assertStringNotContainsString('http', strip_tags($page->html), $page->slug.' shows a URL that is not a link.');
            [$body] = explode('>Sources</h2>', $page->html, 2);
            self::assertMatchesRegularExpression('/\[\d+\]/', $body, $page->slug.' cites no source beside its claims.');
        }
    }

    public function test_every_citation_names_a_listed_source_from_a_permitted_kind_of_site(): void
    {
        $pages = static::getContainer()->get(ComparisonPages::class);
        self::assertInstanceOf(ComparisonPages::class, $pages);

        foreach ($pages->all() as $page) {
            [$body, $sources] = explode('>Sources</h2>', $page->html, 2);
            preg_match_all('/\[(\d+)\]/', $body, $cited);
            $listed = substr_count($sources, '<li>');
            foreach ($cited[1] as $number) {
                self::assertLessThanOrEqual($listed, (int) $number, $page->slug.' cites ['.$number.'], which is not in its sources.');
            }
            self::assertDoesNotMatchRegularExpression(
                '#href="https?://([a-z.]+\.)?(reddit\.com|news\.ycombinator\.com|x\.com|twitter\.com)#',
                $sources,
                $page->slug.' cites a discussion site, not the competitor site, README or a run.',
            );
        }
    }

    public function test_no_content_file_runs_two_list_items_together(): void
    {
        $files = glob(\dirname(__DIR__, 4).'/src/Module/Landing/Resources/compare/*.md');
        self::assertIsArray($files);

        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/[a-z.]- [A-Z]/', (string) file_get_contents($file), basename($file));
        }
    }
}
