<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class HideDebugErrorPageTest extends WebTestCase
{
    public function test_an_error_page_does_not_quote_the_test_source(): void
    {
        $this->assertVisitorErrorPage(__FUNCTION__);
    }

    public function test_the_cli_runtime_mode_renders_the_visitor_error_page(): void
    {
        $previous = $_SERVER['APP_RUNTIME_MODE'] ?? null;
        $_SERVER['APP_RUNTIME_MODE'] = 'web=0';

        try {
            $this->assertVisitorErrorPage(__FUNCTION__);
        } finally {
            if (null === $previous) {
                unset($_SERVER['APP_RUNTIME_MODE']);
            } else {
                $_SERVER['APP_RUNTIME_MODE'] = $previous;
            }
        }
    }

    private function assertVisitorErrorPage(string $testName): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/no-such-page-for-the-error-page-test');

        self::assertResponseStatusCodeSame(404);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('lp-error', $body);
        self::assertStringNotContainsString($testName, $body);
    }
}
