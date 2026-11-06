<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * The body of a refused response without the source lines that the debug error
 * page quotes, so a string a test asserts absent does not match its own source.
 */
final readonly class RefusedPage
{
    public static function body(Response $response): string
    {
        return (string) preg_replace(
            '#<div id="trace-html-[^"]*" class="trace-code[^"]*">\s*(?:<ol\b.*?</ol>)?\s*</div>#s',
            '',
            (string) $response->getContent(),
        );
    }
}
