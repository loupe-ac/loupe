<?php

declare(strict_types=1);

namespace App\Tests\DesignStarter;

use App\DesignStarter\StylesheetSplitter;
use PHPUnit\Framework\TestCase;

final class StylesheetSplitterTest extends TestCase
{
    public function test_it_never_cuts_inside_a_nested_block(): void
    {
        $head = '@layer base,components;.x{/* } */content:"}{"}';
        $media = '@media (min-width:40rem){.a{color:red}.b{color:blue}}';
        $tail = '.y{margin:0}';

        $parts = new StylesheetSplitter()->split($head.$media.$tail, \strlen($media) + 3);

        self::assertSame([$head, $media, $tail], $parts);
    }

    public function test_it_cuts_inside_a_block_that_is_over_the_limit_and_repeats_its_head(): void
    {
        $css = '@layer components{.a{color:red}.b{color:blue}.c{color:green}}';

        $parts = new StylesheetSplitter()->split($css, 40);

        self::assertSame(['@layer components{.a{color:red}}', '@layer components{.b{color:blue}}', '@layer components{.c{color:green}}'], $parts);
    }

    public function test_it_keeps_a_small_stylesheet_in_one_part(): void
    {
        self::assertSame(['.a{color:red}.b{color:blue}'], new StylesheetSplitter()->split('.a{color:red}.b{color:blue}', 1000));
    }

    public function test_it_refuses_a_rule_over_the_limit(): void
    {
        $this->expectException(\RuntimeException::class);

        new StylesheetSplitter()->split('.a{color:red}', 5);
    }
}
