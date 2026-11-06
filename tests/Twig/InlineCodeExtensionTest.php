<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\InlineCodeExtension;
use PHPUnit\Framework\TestCase;

final class InlineCodeExtensionTest extends TestCase
{
    public function test_a_pair_of_backticks_becomes_a_code_element(): void
    {
        self::assertSame('Run <code>loupe login</code> now.', (string) new InlineCodeExtension()->inlineCode('Run `loupe login` now.'));
    }

    public function test_other_markup_is_escaped(): void
    {
        self::assertSame('&lt;b&gt;x&lt;/b&gt; <code>a</code>', (string) new InlineCodeExtension()->inlineCode('<b>x</b> `a`'));
    }

    public function test_text_without_backticks_stays_as_it_is(): void
    {
        self::assertSame('Plain text', (string) new InlineCodeExtension()->inlineCode('Plain text'));
    }

    public function test_a_lone_backtick_stays(): void
    {
        self::assertSame('a ` b', (string) new InlineCodeExtension()->inlineCode('a ` b'));
    }
}
