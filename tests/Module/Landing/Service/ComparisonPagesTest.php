<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Service;

use App\Module\Landing\Service\ComparisonPages;
use App\Site\SiteHost;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ComparisonPagesTest extends TestCase
{
    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/compare-pages-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function test_it_returns_every_page_sorted_by_slug(): void
    {
        $this->write('zeta', $this->frontMatter().'Zeta body.');
        $this->write('alpha', $this->frontMatter(['competitor' => 'Alpha']).'Alpha body.');

        $pages = $this->pages()->all();

        self::assertSame(['alpha', 'zeta'], array_map(static fn ($page): string => $page->slug, $pages));
        self::assertSame('Alpha', $pages[0]->competitor);
        self::assertSame('Loupe vs Rival', $pages[0]->title);
        self::assertSame('How they differ.', $pages[0]->description);
        self::assertSame('v1.0.0 (2026-10-01)', $pages[0]->competitorVersion);
        self::assertSame('2026-11-02', $pages[0]->comparedOn->format('Y-m-d'));
        self::assertStringContainsString('<p>Alpha body.</p>', $pages[0]->html);
    }

    public function test_it_finds_a_page_by_slug_and_returns_null_for_an_unknown_slug(): void
    {
        $this->write('alpha', $this->frontMatter().'Body.');

        self::assertSame('alpha', $this->pages()->find('alpha')?->slug);
        self::assertNull($this->pages()->find('beta'));
        self::assertNull($this->pages()->find('../alpha'));
        self::assertNull($this->pages()->find('ALPHA'));
    }

    public function test_a_missing_folder_holds_no_page(): void
    {
        $pages = new ComparisonPages($this->directory.'/absent', new SiteHost('', 'https://app.example'), 'https://app.example');

        self::assertSame([], $pages->all());
    }

    public function test_it_ignores_a_file_that_is_not_markdown(): void
    {
        file_put_contents($this->directory.'/notes.txt', 'x');

        self::assertSame([], $this->pages()->all());
    }

    public function test_it_renders_a_table(): void
    {
        $this->write('alpha', $this->frontMatter()."| A | B |\n|---|---|\n| 1 | 2 |\n");

        self::assertStringContainsString('<table>', $this->pages()->all()[0]->html);
    }

    public function test_it_escapes_raw_html_and_drops_an_unsafe_link(): void
    {
        $this->write('alpha', $this->frontMatter()."<script>alert(1)</script>\n\n[click](javascript:alert(1))\n");

        $html = $this->pages()->all()[0]->html;

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function test_an_outside_link_opens_with_noopener_and_an_own_host_link_does_not(): void
    {
        $this->write('alpha', $this->frontMatter()."[out](https://rival.example/docs) [in](https://site.example/privacy) [rel](/privacy)\n");

        $html = $this->pages()->all()[0]->html;

        self::assertMatchesRegularExpression('#<a rel="noopener noreferrer" href="https://rival\.example/docs">#', $html);
        self::assertDoesNotMatchRegularExpression('#<a [^>]*rel=[^>]*href="https://site\.example/privacy"#', $html);
        self::assertDoesNotMatchRegularExpression('#<a [^>]*rel=[^>]*href="/privacy"#', $html);
    }

    public function test_a_citation_links_to_its_numbered_source_and_the_source_carries_the_target_id(): void
    {
        $this->write('alpha', $this->frontMatter()."A claim. [2]\n\n| A | B |\n|---|---|\n| x [1] | y |\n\n## Sources\n\nRead today.\n\n1. [One](https://one.example)\n2. [Two](https://two.example)\n");

        $html = $this->pages()->all()[0]->html;

        self::assertStringContainsString('A claim. <a data-turbo="false" href="#source-2">[2]</a>', $html);
        self::assertStringContainsString('x <a data-turbo="false" href="#source-1">[1]</a>', $html);
        self::assertStringContainsString('<li id="source-1">', $html);
        self::assertStringContainsString('<li id="source-2">', $html);
    }

    public function test_a_bracketed_number_in_code_or_in_a_link_stays_as_it_is(): void
    {
        $this->write('alpha', $this->frontMatter()."`list[1]` and [[1]](https://x.example)\n\n## Sources\n\n1. [One](https://one.example)\n");

        $html = $this->pages()->all()[0]->html;

        self::assertStringContainsString('<code>list[1]</code>', $html);
        self::assertStringContainsString('href="https://x.example">[1]</a>', $html);
        self::assertStringNotContainsString('href="#source-1"', $html);
    }

    public function test_a_citation_with_no_listed_source_fails_with_the_file_name(): void
    {
        $this->write('alpha', $this->frontMatter()."A claim. [3]\n\n## Sources\n\n1. [One](https://one.example)\n");

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('alpha.md cites [3]');

        $this->pages()->all();
    }

    /** @return iterable<string, array{string}> */
    public static function missingFields(): iterable
    {
        foreach (['competitor', 'title', 'description', 'comparedOn', 'competitorVersion'] as $field) {
            yield $field => [$field];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingFields')]
    public function test_a_missing_field_fails_with_the_file_and_field_name(string $field): void
    {
        $this->write('alpha', $this->frontMatter([$field => null]).'Body.');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('alpha.md');
        $this->expectExceptionMessage($field);

        $this->pages()->all();
    }

    public function test_a_file_with_no_front_matter_fails(): void
    {
        $this->write('alpha', 'Just a body.');

        $this->expectException(\UnexpectedValueException::class);

        $this->pages()->all();
    }

    public function test_a_malformed_date_fails(): void
    {
        $this->write('alpha', $this->frontMatter(['comparedOn' => '"2026-13-45"']).'Body.');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('comparedOn');

        $this->pages()->all();
    }

    public function test_a_slug_outside_the_allowed_pattern_fails(): void
    {
        $this->write('Bad_Slug', $this->frontMatter().'Body.');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Bad_Slug.md');

        $this->pages()->all();
    }

    private function pages(): ComparisonPages
    {
        return new ComparisonPages($this->directory, new SiteHost('site.example', 'https://app.example'), 'https://app.example');
    }

    private function write(string $slug, string $content): void
    {
        file_put_contents($this->directory.'/'.$slug.'.md', $content);
    }

    /** @param array<string, string|null> $overrides a null value drops the field */
    private function frontMatter(array $overrides = []): string
    {
        $fields = $overrides + [
            'competitor' => 'Rival',
            'title' => 'Loupe vs Rival',
            'description' => 'How they differ.',
            'comparedOn' => '2026-11-02',
            'competitorVersion' => 'v1.0.0 (2026-10-01)',
        ];
        $lines = ['---'];
        foreach ($fields as $name => $value) {
            if (null !== $value) {
                $lines[] = $name.': '.(str_starts_with($value, '"') || 'comparedOn' === $name ? $value : '"'.$value.'"');
            }
        }
        $lines[] = "---\n";

        return implode("\n", $lines);
    }
}
