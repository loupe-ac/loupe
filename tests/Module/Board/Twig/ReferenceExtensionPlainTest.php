<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Twig;

use App\Module\Board\Reference\ReferenceLinker;
use App\Module\Board\Twig\ReferenceExtension;
use App\Module\Review\Service\ReferenceTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferenceExtensionPlainTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function titles(): iterable
    {
        yield 'card token' => ['Follow up on card:1116', 'Follow up on card #1116'];
        yield 'pull request token' => ['Review pr:acme/loupe#1295', 'Review acme/loupe#1295'];
        yield 'plain text' => ['No token here', 'No token here'];
        yield 'null' => [null, ''];
    }

    #[DataProvider('titles')]
    public function test_a_title_shows_its_tokens_as_plain_text(?string $title, string $expected): void
    {
        $extension = new ReferenceExtension((new \ReflectionClass(ReferenceLinker::class))->newInstanceWithoutConstructor(), new ReferenceTokens());

        self::assertSame($expected, $extension->plain($title));
    }
}
