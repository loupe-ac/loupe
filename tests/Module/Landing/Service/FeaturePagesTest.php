<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Service;

use App\Module\Landing\Service\FeaturePages;
use App\Site\SiteHost;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class FeaturePagesTest extends TestCase
{
    private const string SOURCE = __DIR__.'/../../../../src/Module/Landing/Resources/features';

    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/feature-pages-'.bin2hex(random_bytes(4));
        new Filesystem()->mirror(self::SOURCE, $this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function test_it_parses_the_eight_shipped_files_in_carousel_order(): void
    {
        $pages = new FeaturePages(self::SOURCE, $this->siteHost(), 'https://app.example')->all();

        self::assertSame(
            ['board', 'workflow_rules', 'sessions', 'document_review', 'inbox', 'agent_review', 'site_review', 'analytics'],
            array_map(static fn ($page): string => $page->key, $pages),
        );
        foreach ($pages as $page) {
            self::assertNotSame('', $page->label);
            self::assertNotSame('', $page->title);
            self::assertNotSame('', $page->lead);
            self::assertNotSame('', $page->description);
            self::assertCount(3, $page->blocks);
            self::assertCount(4, $page->mockups);
            self::assertStringStartsWith('lucide:', $page->icon);
            self::assertStringStartsWith('/docs/using/', $page->docsPath);
        }
    }

    public function test_the_slug_pattern_matches_the_slugs_of_the_entries(): void
    {
        $pages = new FeaturePages(self::SOURCE, $this->siteHost(), 'https://app.example');

        self::assertSame(FeaturePages::SLUG_PATTERN, implode('|', $pages->slugs()));
    }

    public function test_it_reads_the_copy_word_for_word(): void
    {
        $page = new FeaturePages(self::SOURCE, $this->siteHost(), 'https://app.example')->find('inbox');

        self::assertNotNull($page);
        self::assertSame('Inbox', $page->label);
        self::assertSame('Your agents have questions. Answer them in one place.', $page->title);
        self::assertSame('A push when an agent is blocked.', $page->blocks[2]->title);
        self::assertSame('A blocking question sends a push to your laptop or phone, unless you\'re already in Loupe.', $page->blocks[2]->text);
        self::assertSame('@Landing/features/mockups/inbox/hero.html.twig', $page->mockups[0]);
        self::assertSame('@Landing/features/mockups/inbox/block-3.html.twig', $page->mockups[3]);
    }

    public function test_it_finds_no_page_for_an_unknown_slug(): void
    {
        $pages = new FeaturePages(self::SOURCE, $this->siteHost(), 'https://app.example');

        self::assertNull($pages->find('nope'));
        self::assertNull($pages->find('BOARD'));
    }

    public function test_a_missing_file_throws(): void
    {
        unlink($this->directory.'/board.md');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('board.md is missing');

        $this->pages()->all();
    }

    public function test_a_missing_text_field_throws(): void
    {
        $this->replaceInFile('board.md', 'lead:', 'lede:');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('non-empty text field lead');

        $this->pages()->all();
    }

    public function test_a_block_without_text_throws(): void
    {
        $file = $this->directory.'/analytics.md';
        $source = (string) file_get_contents($file);
        file_put_contents($file, preg_replace('/^    text: .*\n/m', '', $source, 1));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('blocks[0].text');

        $this->pages()->all();
    }

    public function test_two_blocks_throw(): void
    {
        $file = $this->directory.'/analytics.md';
        $source = (string) file_get_contents($file);
        file_put_contents($file, preg_replace('/^  - title: "Is the change real\?"\n    text: .*\n/m', '', $source));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('list of 3 blocks');

        $this->pages()->all();
    }

    public function test_a_file_with_no_matching_slug_throws(): void
    {
        file_put_contents($this->directory.'/extra.md', "---\nlabel: x\n---\n");

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('extra.md matches no feature slug');

        $this->pages()->all();
    }

    private function pages(): FeaturePages
    {
        return new FeaturePages($this->directory, $this->siteHost(), 'https://app.example');
    }

    private function siteHost(): SiteHost
    {
        return new SiteHost('', 'https://app.example');
    }

    private function replaceInFile(string $name, string $search, string $replace): void
    {
        $file = $this->directory.'/'.$name;
        file_put_contents($file, str_replace($search, $replace, (string) file_get_contents($file)));
    }
}
