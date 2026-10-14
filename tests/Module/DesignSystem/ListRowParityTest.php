<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Command\ExportDesignSystemCommand;
use App\Module\DesignSystem\Command\ExportDesignSystemHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

final class ListRowParityTest extends KernelTestCase
{
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

    public function test_the_row_renders_the_icon_the_title_and_the_meta_inside_the_link(): void
    {
        $html = $this->render('<twig:Ds:ListRow href="/inbox?ask=1" title="Which format?" class="extra" data-inbox-request="1" icon:data-inbox-kind="question"><twig:block name="icon">I</twig:block><twig:block name="meta">M</twig:block></twig:Ds:ListRow>');

        self::assertStringContainsString('<a class="lp-list-row extra" href="/inbox?ask=1" data-inbox-request="1">', $html);
        self::assertStringContainsString('<span class="lp-list-row__icon" data-inbox-kind="question">I</span>', $html);
        self::assertStringContainsString('<span class="lp-list-row__title">Which format?</span>', $html);
        self::assertStringContainsString('<span class="lp-list-row__meta">M</span>', $html);
        self::assertStringNotContainsString('aria-current', $html);
        self::assertStringNotContainsString('icon:', $html);
    }

    public function test_the_current_row_carries_the_aria_current_mark(): void
    {
        $html = $this->render('<twig:Ds:ListRow href="#" title="A" :current="true" />');

        self::assertStringContainsString('aria-current="true"', $html);
        self::assertStringNotContainsString(' title="A"', $html);
    }

    public function test_the_exported_wrapper_uses_the_same_class_names_as_the_twig_component(): void
    {
        $html = $this->render('<twig:Ds:ListRow href="#" title="A"><twig:block name="icon">i</twig:block><twig:block name="meta">m</twig:block></twig:Ds:ListRow>');

        $directory = sys_get_temp_dir().'/ds-export-parity-'.bin2hex(random_bytes(4));
        $handler = static::getContainer()->get(ExportDesignSystemHandler::class);
        self::assertInstanceOf(ExportDesignSystemHandler::class, $handler);

        try {
            $handler(new ExportDesignSystemCommand($directory));
            $jsx = (string) file_get_contents($directory.'/components/layout/ListRow/ListRow.jsx');
        } finally {
            new Filesystem()->remove($directory);
        }

        $expected = $this->classNames($jsx);
        $actual = $this->classNames($html);
        sort($expected);
        sort($actual);

        self::assertSame($actual, $expected);
    }
}
