<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Site\SiteChrome;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/** The site bar and footer go into the docs pages too, so each link must work from any of them. */
final class SiteChromeTest extends KernelTestCase
{
    private const string SITE_HOST = 'loupe.example';

    private string $previousSiteHost = '';

    private string $previousBlogHost = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->previousSiteHost = (string) ($_SERVER['SITE_HOST'] ?? '');
        $this->previousBlogHost = (string) ($_SERVER['BLOG_PROXY_HOST'] ?? '');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->setEnv('SITE_HOST', $this->previousSiteHost);
        $this->setEnv('BLOG_PROXY_HOST', $this->previousBlogHost);
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        yield 'bar' => ['@Landing/landing/_nav.html.twig'];
        yield 'footer' => ['@Landing/landing/_footer.html.twig'];
    }

    #[DataProvider('templates')]
    public function test_no_link_points_at_a_section_of_the_current_page(string $template): void
    {
        foreach (['', self::SITE_HOST] as $siteHost) {
            $html = $this->render($template, $siteHost, '');

            self::assertNotSame([], ChromeLinks::hrefs($html));
            self::assertSame([], array_filter(
                ChromeLinks::hrefs($html),
                static fn (string $href): bool => str_starts_with($href, '#'),
            ), sprintf('%s with site host "%s"', $template, $siteHost));
        }
    }

    #[DataProvider('templates')]
    public function test_every_link_of_a_split_instance_names_a_configured_or_outside_host(string $template): void
    {
        $html = $this->render($template, self::SITE_HOST, '');

        self::assertSame([], ChromeLinks::problems($html, $this->configuredHosts()));
    }

    public function test_the_audit_flags_a_fragment_a_relative_link_and_an_unknown_host(): void
    {
        $html = '<a href="#top">a</a><a href="/blog">b</a><a href="https://bsky.app/x">c</a><a href="https://x.com/y">d</a><a href="https://loupe.example/">e</a>';

        self::assertCount(3, ChromeLinks::problems($html, [self::SITE_HOST]));
        self::assertSame(['x.com'], SiteChrome::OUTSIDE_HOSTS);
    }

    public function test_the_bar_links_the_docs_blog_and_source_then_sign_in_and_the_waitlist(): void
    {
        $html = $this->render('@Landing/landing/_nav.html.twig', self::SITE_HOST, '');
        $hrefs = ChromeLinks::hrefs($html);

        self::assertContains('https://loupe.example/', $hrefs);
        self::assertContains('https://loupe.example/blog', $hrefs);
        self::assertContains('https://github.com/ubermuda/loupe', $hrefs);
        self::assertContains('https://loupe.example/waitlist', $hrefs);
        self::assertContains('http://localhost/login', $hrefs);
        self::assertContains('http://localhost/', $hrefs);
        self::assertStringContainsString('modal#open', $html);
        self::assertStringNotContainsString('requirements', $html);
    }

    public function test_the_bar_holds_a_hidden_go_to_app_button_for_the_signed_in_state(): void
    {
        $html = $this->render('@Landing/landing/_nav.html.twig', self::SITE_HOST, '');

        $button = (new Crawler('<body>'.$html.'</body>'))->filter('a[data-site-chrome="go-to-app"]');

        self::assertCount(1, $button);
        self::assertNotNull($button->attr('hidden'));
        self::assertStringContainsString('Go to app', $html);
        self::assertCount(2, (new Crawler('<body>'.$html.'</body>'))->filter('[data-site-chrome="signed-out"]'));
    }

    public function test_the_footer_always_lists_blog_on_a_split_instance(): void
    {
        $html = $this->render('@Landing/landing/_footer.html.twig', self::SITE_HOST, '');

        self::assertContains('https://loupe.example/blog', ChromeLinks::hrefs($html));
    }

    public function test_an_instance_with_no_site_host_and_no_blog_host_lists_no_blog(): void
    {
        foreach (['@Landing/landing/_nav.html.twig', '@Landing/landing/_footer.html.twig'] as $template) {
            self::assertNotContains('/blog', ChromeLinks::hrefs($this->render($template, '', '')));
        }
    }

    public function test_an_instance_with_a_blog_host_lists_blog_on_its_own_origin(): void
    {
        $html = $this->render('@Landing/landing/_footer.html.twig', '', 'blog.example.com');

        self::assertContains('/blog', ChromeLinks::hrefs($html));
    }

    /** @return list<string> */
    private function configuredHosts(): array
    {
        $hosts = [self::SITE_HOST];
        foreach (['DEFAULT_URI', 'APP_SOURCE_URL'] as $variable) {
            $hosts[] = (string) parse_url((string) ($_SERVER[$variable] ?? $_ENV[$variable] ?? ''), \PHP_URL_HOST);
        }
        $hosts[] = (string) parse_url((string) static::getContainer()->getParameter('app.docs_url'), \PHP_URL_HOST);

        return array_values(array_filter($hosts));
    }

    private function render(string $template, string $siteHost, string $blogHost): string
    {
        self::ensureKernelShutdown();
        $this->setEnv('SITE_HOST', $siteHost);
        $this->setEnv('BLOG_PROXY_HOST', $blogHost);
        self::bootKernel();
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render($template);
    }

    private function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
