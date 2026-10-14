<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RobotsControllerTest extends WebTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        $this->setBlogProxyHost('');

        parent::tearDown();
    }

    public function test_a_visitor_with_no_session_reads_the_rules_as_plain_text(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));
        $lines = explode("\n", (string) $client->getResponse()->getContent());
        foreach (['User-agent: *', 'Allow: /$', 'Allow: /privacy$', 'Allow: /terms$', 'Allow: /ai-policy$', 'Allow: /waitlist$', 'Allow: /login$', 'Allow: /register$', 'Allow: /assets/', 'Disallow: /'] as $line) {
            self::assertContains($line, $lines);
        }
    }

    public function test_each_page_rule_ends_with_an_anchor_so_no_subpath_matches(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/robots.txt');

        foreach (explode("\n", (string) $client->getResponse()->getContent()) as $line) {
            if (str_starts_with($line, 'Allow: ') && 'Allow: /assets/' !== $line) {
                self::assertStringEndsWith('$', $line);
            }
        }
    }

    public function test_the_sitemap_line_uses_the_configured_address_not_the_request_host(): void
    {
        $client = static::createClient();
        $appUrl = static::getContainer()->getParameter('app.url');
        self::assertIsString($appUrl);

        $client->request(Request::METHOD_GET, '/robots.txt', server: ['HTTP_HOST' => 'evil.example']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Sitemap: '.rtrim($appUrl, '/').'/sitemap.xml', $body);
        self::assertStringNotContainsString('evil.example', $body);
    }

    public function test_with_a_blog_it_allows_the_blog_before_the_catch_all_and_lists_its_sitemap(): void
    {
        $this->setBlogProxyHost('blog.example.com');
        $client = static::createClient();
        $base = $this->base();

        $client->request(Request::METHOD_GET, '/robots.txt');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $lines = explode("\n", $body);
        $disallow = array_search('Disallow: /', $lines, true);
        self::assertIsInt($disallow);
        foreach (['Allow: /blog$', 'Allow: /blog/'] as $rule) {
            $position = array_search($rule, $lines, true);
            self::assertIsInt($position, $rule);
            self::assertLessThan($disallow, $position, $rule);
        }
        $sitemap = array_search('Sitemap: '.$base.'/sitemap.xml', $lines, true);
        self::assertIsInt($sitemap);
        self::assertSame('Sitemap: '.$base.'/blog/sitemap.xml', $lines[$sitemap + 1]);
        self::assertStringNotContainsString('blog.example.com', $body);
    }

    public function test_without_a_blog_it_names_no_blog_path(): void
    {
        $this->setBlogProxyHost('');
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/robots.txt');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Disallow: /', $body);
        self::assertStringNotContainsString('/blog', $body);
    }

    private function base(): string
    {
        $appUrl = static::getContainer()->getParameter('app.url');
        self::assertIsString($appUrl);

        return rtrim($appUrl, '/');
    }

    private function setBlogProxyHost(string $host): void
    {
        $_ENV['BLOG_PROXY_HOST'] = $_SERVER['BLOG_PROXY_HOST'] = $host;
    }
}
