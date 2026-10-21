<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Command;

use App\Module\Landing\Command\StampDocsCommand;
use App\Module\Landing\Command\StampDocsHandler;
use App\Module\Landing\Command\StampDocsResult;
use App\Site\SiteChrome;
use App\Site\SiteLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class StampDocsHandlerTest extends KernelTestCase
{
    private const string PAGE = '<!doctype html><html><head><meta property="og:image" content="https://loupe.ac/assets/images/og/hero.png"></head><body><header><site-chrome part-name="nav"><template shadowrootmode="open"><!--LOUPE_SITE_NAV--></template></site-chrome></header><main data-pagefind-body>Docs</main><div class="mobile-preferences"><site-chrome part-name="menu"><template shadowrootmode="open"><!--LOUPE_SITE_MENU--></template></site-chrome></div><site-chrome part-name="footer"><template shadowrootmode="open"><!--LOUPE_SITE_FOOTER--></template></site-chrome></body></html>';

    private const string CSS = '@layer theme, base, components, utilities; .lp-home-nav { color: red; } .lp-home-footer { color: blue; } .lp-home-footer__social { color: teal; } .lp-btn { color: green; } .lp-unrelated { color: pink; }';

    private string $work = '';

    /** @var array<string, string> */
    private array $previous = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir().'/stamp-docs-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir([$this->work.'/build/getting-started', $this->work.'/served']);
        file_put_contents($this->work.'/build/getting-started/index.html', self::PAGE);
        file_put_contents($this->work.'/app.css', self::CSS);
        foreach (['SITE_HOST' => 'site.example', 'DEFAULT_URI' => 'https://app.example'] as $name => $value) {
            $this->previous[$name] = (string) ($_SERVER[$name] ?? '');
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->work);
        foreach ($this->previous as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        parent::tearDown();
    }

    public function test_it_puts_the_bar_and_footer_in_place_of_the_markers(): void
    {
        $result = $this->stamp();

        self::assertSame([], $result->problems);
        self::assertSame(1, $result->pages);
        $html = (string) file_get_contents($this->work.'/served/getting-started/index.html');
        self::assertStringNotContainsString('LOUPE_SITE_', $html);
        self::assertStringContainsString('<template shadowrootmode="open"><style>', $html);
        self::assertStringContainsString('href="https://app.example/login"', $html);
        self::assertStringContainsString('href="https://site.example/blog"', $html);
        self::assertStringContainsString('.lp-home-nav { color: red; }', $html);
        self::assertStringNotContainsString('lp-unrelated', $html);
        self::assertStringContainsString(self::PAGE, (string) file_get_contents($this->work.'/build/getting-started/index.html'));
    }

    public function test_the_docs_bar_and_the_menu_link_the_discord_server_and_the_landing_bar_does_not(): void
    {
        $result = $this->stamp(null, fn () => $this->setLink(SiteLinks::DISCORD_FLAG, 'https://discord.gg/loupe'));

        self::assertSame([], $result->problems);
        $html = (string) file_get_contents($this->work.'/served/getting-started/index.html');
        $docsBar = (string) strstr($html, '<header>');
        $docsBar = substr($docsBar, 0, (int) strpos($docsBar, '</header>'));
        self::assertStringContainsString('lp-home-nav__link--section" target="_blank" rel="noopener noreferrer">Discord</a>', $docsBar);
        self::assertSame(3, substr_count($html, 'href="https://discord.gg/loupe"'), 'The docs bar, the footer and the phone menu each link it once.');

        $menu = (string) strstr($html, 'part-name="menu"');
        self::assertStringContainsString('href="https://discord.gg/loupe"', $menu);
        self::assertStringContainsString('href="https://github.com/loupe-ac/loupe"', $menu);
        self::assertStringContainsString('lp-home-footer__social', $menu);

        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        self::assertStringNotContainsString('discord.gg', $twig->render('@Landing/landing/_nav.html.twig'));
    }

    public function test_the_menu_has_no_discord_link_while_the_flag_is_empty(): void
    {
        $this->stamp();

        $html = (string) file_get_contents($this->work.'/served/getting-started/index.html');
        $menu = (string) strstr($html, 'part-name="menu"');
        self::assertStringContainsString('href="https://github.com/loupe-ac/loupe"', $menu);
        self::assertStringNotContainsString('Discord', $menu);
        self::assertStringNotContainsString('Discord', substr($html, 0, (int) strpos($html, '</header>')));
    }

    public function test_it_fails_when_a_docs_page_has_no_menu_marker(): void
    {
        file_put_contents($this->work.'/build/nomenu.html', str_replace('<!--LOUPE_SITE_MENU-->', '', self::PAGE));

        $result = $this->stamp();

        self::assertStringContainsString('nomenu.html', implode("\n", $result->problems));
    }

    public function test_it_gives_the_open_graph_image_the_address_of_the_running_site(): void
    {
        $this->stamp();

        $html = (string) file_get_contents($this->work.'/served/getting-started/index.html');
        self::assertStringNotContainsString(SiteChrome::OG_IMAGE_PLACEHOLDER, $html);
        self::assertMatchesRegularExpression('#content="https://site\.example/assets/images/og/hero[^"]*\.png"#', $html);
    }

    public function test_a_database_that_is_down_gives_the_default_image_and_does_not_fail(): void
    {
        $result = $this->stamp(null, static function (): void {
            $flags = new readonly class(self::createStub(\Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface::class), new \Psr\Log\NullLogger()) extends \Ubermuda\FeatureFlagsBundle\FeatureFlagService {
                #[\Override]
                public function getValue(string $name): mixed
                {
                    throw new \RuntimeException('connection refused');
                }
            };
            static::getContainer()->set(\Ubermuda\FeatureFlagsBundle\FeatureFlagService::class, $flags);
        });

        self::assertSame([], $result->problems);
        $html = (string) file_get_contents($this->work.'/served/getting-started/index.html');
        self::assertMatchesRegularExpression('#content="https://site\.example/assets/images/og/hero[^"]*\.png"#', $html);
    }

    public function test_the_built_head_loads_the_docs_script_by_a_root_relative_address(): void
    {
        $page = (string) file_get_contents(\dirname(__DIR__, 4).'/website/dist/index.html');
        if ('' === $page) {
            self::markTestSkipped('No docs build in website/dist.');
        }

        self::assertMatchesRegularExpression('#<script[^>]*src="/site/docs\.js"#', $page);
    }

    public function test_it_fails_when_a_page_keeps_a_marker(): void
    {
        file_put_contents($this->work.'/build/bad.html', str_replace('<!--LOUPE_SITE_NAV-->', '<!-- LOUPE_SITE_NAV -->', self::PAGE));

        $result = $this->stamp();

        self::assertNotSame([], $result->problems);
        self::assertStringContainsString('bad.html', implode("\n", $result->problems));
    }

    public function test_it_fails_when_a_docs_page_has_no_marker(): void
    {
        file_put_contents($this->work.'/build/bare.html', '<html><body><main data-pagefind-body>x</main></body></html>');

        $result = $this->stamp();

        self::assertStringContainsString('bare.html', implode("\n", $result->problems));
    }

    public function test_it_fails_and_writes_nothing_when_a_link_names_localhost(): void
    {
        $result = $this->stamp(null, static function (): void {
            $twig = static::getContainer()->get(Environment::class);
            self::assertInstanceOf(Environment::class, $twig);
            $twig->setLoader(new ChainLoader([
                new ArrayLoader(['@Landing/landing/_nav.html.twig' => '<a href="http://localhost/login">Sign in</a><a href="https://other.example/">x</a>']),
                $twig->getLoader(),
            ]));
        });

        $problems = implode("\n", $result->problems);
        self::assertStringContainsString('http://localhost/login', $problems);
        self::assertStringContainsString('https://other.example/', $problems);
        self::assertFileDoesNotExist($this->work.'/served/getting-started/index.html');
    }

    public function test_it_accepts_the_host_of_the_x_flag_in_the_footer(): void
    {
        $result = $this->stamp(null, fn () => $this->setLink(SiteLinks::X_FLAG, 'https://x.com/loupehq'));

        self::assertSame([], $result->problems);
        self::assertStringContainsString('href="https://x.com/loupehq"', (string) file_get_contents($this->work.'/served/getting-started/index.html'));
    }

    public function test_it_accepts_the_host_of_a_source_flag(): void
    {
        $result = $this->stamp(null, fn () => $this->setLink(SiteLinks::SOURCE_FLAG, 'https://git.example/fork/loupe'));

        self::assertSame([], $result->problems);
        self::assertStringContainsString('href="https://git.example/fork/loupe"', (string) file_get_contents($this->work.'/served/getting-started/index.html'));
    }

    public function test_a_bad_link_value_is_left_out_and_does_not_fail_the_stamp(): void
    {
        $result = $this->stamp(null, function (): void {
            $this->setLink(SiteLinks::X_FLAG, '/loupehq');
            $this->setLink(SiteLinks::DISCORD_FLAG, 'javascript:alert(1)');
        });

        self::assertSame([], $result->problems);
        $html = (string) file_get_contents($this->work.'/served/getting-started/index.html');
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('/loupehq', $html);
    }

    public function test_it_fails_when_the_source_folder_is_missing(): void
    {
        $result = $this->stamp($this->work.'/nowhere');

        self::assertNotSame([], $result->problems);
    }

    public function test_it_fails_when_the_stylesheet_is_missing(): void
    {
        $handler = static::getContainer()->get(StampDocsHandler::class);
        self::assertInstanceOf(StampDocsHandler::class, $handler);

        $result = $handler(new StampDocsCommand($this->work.'/build', $this->work.'/served', $this->work.'/none.css'));

        self::assertNotSame([], $result->problems);
    }

    public function test_it_replaces_what_an_earlier_start_left_in_the_target(): void
    {
        file_put_contents($this->work.'/served/old.html', 'old');

        $this->stamp();

        self::assertFileDoesNotExist($this->work.'/served/old.html');
    }

    private function setLink(string $flag, string $value): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $repository = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $repository);
        $row = $repository->findAllIndexed()[$flag] ?? null;
        self::assertInstanceOf(FeatureFlag::class, $row, 'A migration seeds the row.');
        $row->value = $value;
        $em->flush();
    }

    private function stamp(?string $source = null, ?\Closure $afterBoot = null): StampDocsResult
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        if (null !== $afterBoot) {
            $afterBoot();
        }
        $handler = static::getContainer()->get(StampDocsHandler::class);
        self::assertInstanceOf(StampDocsHandler::class, $handler);

        return $handler(new StampDocsCommand($source ?? $this->work.'/build', $this->work.'/served', $this->work.'/app.css'));
    }
}
