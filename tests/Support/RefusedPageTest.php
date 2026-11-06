<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RefusedPageTest extends TestCase
{
    public function test_the_body_drops_the_quoted_source_and_keeps_the_rest_of_the_debug_page(): void
    {
        $exception = new AccessDeniedHttpException('Refused for refused-page-message.');
        $page = new HtmlErrorRenderer(true)->render($exception)->getAsString();
        $marker = 'refused-page-source-line';

        // Guard: the debug page quotes this file, so the marker is in the raw page.
        self::assertStringContainsString($marker, $page);

        $body = RefusedPage::body(new Response($page, Response::HTTP_FORBIDDEN));

        self::assertStringNotContainsString($marker, $body);
        self::assertStringContainsString('refused-page-message', $body);
        self::assertStringContainsString('trace-line', $body);
    }
}
