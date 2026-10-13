<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RobotsControllerTest extends WebTestCase
{
    public function test_a_visitor_with_no_session_reads_the_rules_as_plain_text(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));
        $lines = explode("\n", (string) $client->getResponse()->getContent());
        foreach (['User-agent: *', 'Allow: /$', 'Allow: /privacy', 'Allow: /terms', 'Allow: /ai-policy', 'Allow: /waitlist', 'Allow: /login', 'Allow: /register', 'Allow: /assets/', 'Disallow: /'] as $line) {
            self::assertContains($line, $lines);
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
}
