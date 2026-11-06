<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HideDebugErrorPageTest extends WebTestCase
{
    public function test_an_error_page_does_not_quote_the_test_source(): void
    {
        $client = static::createClient();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/no-such-page-for-the-error-page-test');

        self::assertResponseStatusCodeSame(404);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('lp-error', $body);
        self::assertStringNotContainsString(__FUNCTION__, $body);
    }
}
