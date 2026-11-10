<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Entity;

use App\Module\Account\Entity\WaitlistBlockPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WaitlistBlockPatternTest extends TestCase
{
    public function test_the_pattern_is_trimmed_and_lowercased(): void
    {
        $pattern = new WaitlistBlockPattern('  *@Mailinator.COM ');

        self::assertSame('*@mailinator.com', $pattern->pattern);
        self::assertSame('%@mailinator.com', $pattern->likePattern);
    }

    /** @return iterable<string, array{string, string}> */
    public static function likePatterns(): iterable
    {
        yield 'a star is any text' => ['spam*@gmail.com', 'spam%@gmail.com'];
        yield 'a domain suffix' => ['*.ru', '%.ru'];
        yield 'an underscore matches itself' => ['a_b@x.com', 'a\_b@x.com'];
        yield 'a percent sign matches itself' => ['100%@x.com', '100\%@x.com'];
        yield 'a backslash matches itself' => ['a\b*', 'a\\\\b%'];
        yield 'several stars' => ['*spam*', '%spam%'];
    }

    #[DataProvider('likePatterns')]
    public function test_the_like_pattern_escapes_like_wildcards_and_turns_stars_into_percent(string $glob, string $like): void
    {
        self::assertSame($like, WaitlistBlockPattern::computeLikePattern($glob));
        self::assertSame($like, new WaitlistBlockPattern($glob)->likePattern);
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'white space' => ['   '];
        yield 'one star' => ['*'];
        yield 'only stars' => ['***'];
        yield 'stars and spaces' => [' * * '];
    }

    #[DataProvider('rejected')]
    public function test_a_pattern_that_would_hide_everything_or_nothing_is_rejected(string $glob): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WaitlistBlockPattern($glob);
    }
}
