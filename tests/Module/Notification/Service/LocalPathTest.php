<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Notification\Service\LocalPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalPathTest extends TestCase
{
    #[DataProvider('accepted')]
    public function test_a_relative_path_passes_unchanged(string $path): void
    {
        self::assertSame($path, LocalPath::from($path));
    }

    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        yield 'root' => ['/'];
        yield 'page' => ['/projects/abc/board'];
        yield 'encoded space' => ['/a%20b'];
        yield 'colon inside' => ['/a:b'];
    }

    #[DataProvider('refused')]
    public function test_anything_that_can_leave_the_origin_is_refused(?string $value): void
    {
        self::assertNull(LocalPath::from($value));
    }

    /** @return iterable<string, array{?string}> */
    public static function refused(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'relative' => ['account/notifications'];
        yield 'double slash' => ['//evil.example'];
        yield 'double slash with path' => ['//evil.example/a'];
        yield 'slash backslash' => ['/\\evil.example'];
        yield 'backslash inside' => ['/a\\b'];
        yield 'scheme' => ['https://evil.example/'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'tab after slash' => ["/\t/evil.example"];
        yield 'newline' => ["/a\nLocation: https://evil.example"];
        yield 'carriage return' => ["/a\rb"];
        yield 'space' => ['/a b'];
        yield 'nul byte' => ["/a\0b"];
        yield 'too long' => ['/'.str_repeat('a', 2048)];
    }

    #[DataProvider('urls')]
    public function test_the_path_of_a_url_on_the_origin(?string $url, ?string $expected): void
    {
        self::assertSame($expected, LocalPath::ofSameOrigin($url, 'https://loupe.example'));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function urls(): iterable
    {
        yield 'page' => ['https://loupe.example/projects/abc', '/projects/abc'];
        yield 'root' => ['https://loupe.example/', '/'];
        yield 'fragment drops, query stays' => ['https://loupe.example/a?b=1&d=2#c', '/a?b=1&d=2'];
        yield 'no referer' => [null, null];
        yield 'no path' => ['https://loupe.example', null];
        yield 'other host' => ['https://evil.example/a', null];
        yield 'userinfo' => ['https://loupe.example@evil.example/a', null];
        yield 'longer host' => ['https://loupe.example.evil.example/a', null];
        yield 'other port' => ['https://loupe.example:8443/a', null];
        yield 'other scheme' => ['http://loupe.example/a', null];
        yield 'backslash' => ['https://loupe.example/\\evil.example', null];
        yield 'double slash' => ['https://loupe.example//evil.example', null];
    }
}
