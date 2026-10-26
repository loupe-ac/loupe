<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExplainerDemoTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function removedSections(): iterable
    {
        yield 'workshop activity list' => ['class="lp-workshop-recent'];
        yield 'top bar bell' => ['d="M10.268 21a2 2 0 0 0 3.464 0'];
        yield 'metrics rows table' => ['class="lp-metric-table'];
    }

    #[DataProvider('removedSections')]
    public function test_the_demo_does_not_show_a_section_the_app_removed(string $marker): void
    {
        $html = (string) file_get_contents(dirname(__DIR__, 3).'/public/landing/explainer.html');
        self::assertNotSame('', $html);

        self::assertStringNotContainsString($marker, $html);
    }
}
