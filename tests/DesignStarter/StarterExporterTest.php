<?php

declare(strict_types=1);

namespace App\Tests\DesignStarter;

use App\DesignStarter\StarterExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class StarterExporterTest extends KernelTestCase
{
    private const array SHELLS = ['App frame', 'Document review', 'Board', 'Card page', 'Inbox', 'Settings'];

    /** Each export compiles the stylesheet, so two runs serve the whole class and no test changes them. */
    private static string $first;

    private static string $second;

    /** @var list<string> */
    private static array $written;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::bootKernel();
        $exporter = static::getContainer()->get(StarterExporter::class);
        self::assertInstanceOf(StarterExporter::class, $exporter);
        self::$first = sys_get_temp_dir().'/starter-export-'.bin2hex(random_bytes(4));
        self::$second = sys_get_temp_dir().'/starter-export-'.bin2hex(random_bytes(4));
        self::$written = $exporter(self::$first);
        $exporter(self::$second);
        self::ensureKernelShutdown();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove([self::$first, self::$second]);
        parent::tearDownAfterClass();
    }

    public function test_two_runs_write_the_same_bytes(): void
    {
        $paths = self::$written;
        $sorted = $paths;
        sort($sorted);

        self::assertSame($sorted, $paths);
        self::assertContains('starter.json', $paths);
        foreach ($paths as $path) {
            self::assertFileEquals(self::$first.'/'.$path, self::$second.'/'.$path, $path);
        }
    }

    public function test_it_writes_no_support_file(): void
    {
        self::assertNotContains('support.js', self::$written);
    }

    public function test_a_shell_holds_no_script_hidden_or_open_attribute(): void
    {
        foreach (self::SHELLS as $shell) {
            $html = $this->read($shell.'.dc.html');

            self::assertStringNotContainsString('<script', $html, $shell);
            self::assertStringNotContainsString('{{', $html, $shell);
            self::assertSame(0, preg_match('~\s(?:hidden|open)(?=[\s=/>])~i', $html), $shell);
            self::assertStringContainsString('<x-dc>', $html, $shell);
            self::assertStringContainsString('href="styles/app.css"', $html, $shell);
        }
    }

    public function test_a_shell_holds_exactly_one_empty_slot(): void
    {
        foreach (self::SHELLS as $shell) {
            $html = $this->read($shell.'.dc.html');

            self::assertSame(1, substr_count($html, 'data-starter-slot'), $shell);
            self::assertSame(1, preg_match('~<(\w+)\s[^>]*data-starter-slot[^>]*></\1>~', $html), $shell);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function frameControls(): iterable
    {
        yield 'board' => ['Board', ['lp-board-toolbar', 'lp-board-backlog']];
        yield 'inbox' => ['Inbox', ['lp-inbox-topbar-actions', 'lp-inbox-list-head', 'lp-filter-search', 'lp-inbox-filter', 'lp-inbox-rows']];
        yield 'document review' => ['Document review', ['lp-review-topbar-actions']];
        yield 'card page' => ['Card page', ['lp-card-drawer__header-actions', 'lp-card-drawer__footer']];
    }

    /**
     * @param list<string> $classes
     */
    #[DataProvider('frameControls')]
    public function test_a_shell_keeps_the_visible_controls_of_its_page(string $shell, array $classes): void
    {
        $html = $this->read($shell.'.dc.html');

        foreach ($classes as $class) {
            self::assertMatchesRegularExpression('~class="[^"]*\b'.preg_quote($class, '~').'\b~', $html, $class);
        }
    }

    public function test_the_stylesheet_parts_are_small_and_cut_on_rule_boundaries(): void
    {
        $app = $this->read('styles/app.css');
        preg_match_all('~@import "(app-\d+\.css)";~', $app, $matches);

        self::assertGreaterThan(0, \count($matches[1]));
        self::assertSame(array_map(static fn (int $i): string => sprintf('app-%d.css', $i), range(1, \count($matches[1]))), $matches[1]);

        $total = '';
        foreach ($matches[1] as $part) {
            $css = $this->read('styles/'.$part);
            $total .= $css;

            self::assertLessThanOrEqual(StarterExporter::PART_LIMIT, \strlen($css), $part);
            self::assertSame(0, $this->depthAtEnd($css), $part);
        }

        self::assertStringContainsString('url(../assets/fonts/', str_replace(['"', "'"], '', $total));
        self::assertStringNotContainsString('../fonts/', str_replace('../assets/fonts/', '', $total));
        self::assertStringContainsString('.lp-board', $total);
    }

    public function test_the_manifest_hashes_match_the_files(): void
    {
        $manifest = json_decode($this->read('starter.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['starter']);
        self::assertContains('styles/app.css', array_keys($manifest['files']));
        foreach ($manifest['files'] as $path => $hash) {
            self::assertSame($hash, 'sha256:'.hash('sha256', $this->read($path)), $path);
        }
        foreach (self::SHELLS as $shell) {
            self::assertArrayHasKey($shell.'.dc.html', $manifest['shells']);
            self::assertArrayHasKey($shell.'.dc.html', $manifest['files']);
            self::assertSame('[data-starter-slot]', $manifest['shells'][$shell.'.dc.html']['slot']);
        }
        self::assertSame(array_keys($manifest['files']), array_values(array_diff(self::$written, ['starter.json'])));
    }

    public function test_it_copies_the_fonts(): void
    {
        self::assertContains('assets/fonts/Geist-Variable.woff2', self::$written);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(self::$first.'/'.$path);
        self::assertIsString($content, $path);

        return $content;
    }

    private function depthAtEnd(string $css): int
    {
        $css = preg_replace('~/\*.*?\*/|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'~s', '', $css) ?? $css;

        return substr_count($css, '{') - substr_count($css, '}');
    }
}
