<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Tests\Support\ComponentSourceReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class SplitViewParityTest extends KernelTestCase
{
    use ComponentSourceReader;

    private const string PAGE = '<twig:Ds:SplitView class="extra" data-controller="x"><h1>H</h1><div class="lp-split-region"><div class="lp-split-view"><div class="lp-split-pane">L</div><div class="lp-split-pane">D</div></div></div></twig:Ds:SplitView>';

    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    /** @return list<string> */
    private function classNames(string $source): array
    {
        preg_match_all('/lp-[a-z_-]+/', $source, $matches);

        return array_values(array_unique($matches[0]));
    }

    public function test_the_split_view_renders_its_body_in_a_root_that_keeps_the_attributes(): void
    {
        $html = $this->render(self::PAGE);

        self::assertStringContainsString('<div class="lp-split-page extra" data-controller="x">', $html);
        self::assertLessThan(strpos($html, 'lp-split-region'), strpos($html, '<h1>H</h1>'));
        self::assertStringContainsString('<div class="lp-split-pane">D</div>', $html);
    }

    public function test_the_exported_wrapper_uses_the_same_class_names_as_the_twig_component(): void
    {
        $html = $this->render(self::PAGE);

        $jsx = self::componentJsx('SplitView');

        $expected = $this->classNames($jsx);
        $actual = $this->classNames($html);
        sort($expected);
        sort($actual);

        self::assertSame($actual, $expected);
    }
}
