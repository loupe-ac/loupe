<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Routing\PinnedUrlGenerator;
use App\Site\SiteHost;
use App\Tests\Support\ComparisonFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class LlmsControllerTest extends WebTestCase
{
    public function test_a_visitor_with_no_session_reads_a_markdown_index(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/llms.txt');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/markdown', (string) $client->getResponse()->headers->get('Content-Type'));
        $lines = explode("\n", (string) $client->getResponse()->getContent());
        self::assertSame('# Loupe', $lines[0]);
        self::assertStringStartsWith('> ', $lines[2]);
    }

    public function test_it_links_the_setup_instructions_on_the_app_host_and_the_docs(): void
    {
        $client = static::createClient();
        $setupUrl = static::getContainer()->get(PinnedUrlGenerator::class)->generate('app_setup_instructions');
        $docsUrl = static::getContainer()->getParameter('app.docs_url');
        self::assertIsString($docsUrl);

        $client->request(Request::METHOD_GET, '/llms.txt');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(']('.$setupUrl.'): ', $body);
        self::assertStringContainsString(']('.rtrim($docsUrl, '/').'/): ', $body);
    }

    public function test_it_lists_each_comparison_page_on_the_site_origin_when_a_site_host_is_set(): void
    {
        $previous = (string) ($_SERVER['SITE_HOST'] ?? '');
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = 'loupe.example';
        $client = static::createClient();
        $fixtures = ComparisonFixtures::install(static::getContainer(), new SiteHost('loupe.example', 'http://localhost'), 'http://localhost');

        try {
            $client->request(Request::METHOD_GET, '/llms.txt', server: ['HTTP_HOST' => 'loupe.example']);

            $body = (string) $client->getResponse()->getContent();
            self::assertStringContainsString("\n## Compare\n", $body);
            self::assertStringContainsString("\n## FAQ\n\n- [Questions people ask about Loupe](https://loupe.example/faq): ", $body);
            self::assertStringContainsString("- [Loupe vs Fixture's Rival](https://loupe.example/compare/".ComparisonFixtures::SLUG.'): '.ComparisonFixtures::DESCRIPTION, $body);
        } finally {
            $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $previous;
            ComparisonFixtures::remove($fixtures);
        }
    }

    public function test_it_has_no_compare_section_without_a_site_host(): void
    {
        $previous = (string) ($_SERVER['SITE_HOST'] ?? '');
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = '';
        $client = static::createClient();
        $fixtures = ComparisonFixtures::install(static::getContainer(), new SiteHost('', 'http://localhost'), 'http://localhost');

        try {
            $client->request(Request::METHOD_GET, '/llms.txt');

            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('## Compare', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('## FAQ', (string) $client->getResponse()->getContent());
        } finally {
            $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $previous;
            ComparisonFixtures::remove($fixtures);
        }
    }

    public function test_the_setup_link_names_the_configured_host_not_the_request_host(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/llms.txt', server: ['HTTP_HOST' => 'evil.example']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/setup.md): ', $body);
        self::assertStringNotContainsString('evil.example', $body);
    }
}
