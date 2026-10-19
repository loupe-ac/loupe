<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use App\Tests\Support\ComponentSourceReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class PageHeaderParityTest extends KernelTestCase
{
    use ComponentSourceReader;

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

    public function test_the_page_header_renders_the_copy_block_then_the_actions(): void
    {
        $html = $this->render('<twig:Ds:PageHeader title="Documents" description="All of them" class="extra"><twig:block name="actions"><div id="a">Go</div></twig:block></twig:Ds:PageHeader>');

        self::assertStringContainsString('<header class="lp-page-header extra">', $html);
        self::assertStringContainsString('<h1 class="lp-workspace-title">Documents</h1>', $html);
        self::assertStringContainsString('<p class="lp-workspace-desc">All of them</p>', $html);
        self::assertLessThan(strpos($html, '<div id="a">'), strpos($html, 'lp-page-header__copy'));
    }

    public function test_the_workshop_variant_and_the_title_class_change_the_heading(): void
    {
        $html = $this->render('<twig:Ds:PageHeader variant="workshop" title="Workshop" />');

        self::assertStringContainsString('class="lp-page-header lp-page-header--workshop"', $html);
        self::assertStringContainsString('<h1 class="lp-workshop-title">Workshop</h1>', $html);
        self::assertStringNotContainsString('lp-workspace-desc', $html);

        $html = $this->render('<twig:Ds:PageHeader title="Doc" titleClass="lp-workspace-title--doc" />');

        self::assertStringContainsString('<h1 class="lp-workspace-title lp-workspace-title--doc">Doc</h1>', $html);
    }

    public function test_the_exported_wrapper_uses_the_same_class_names_as_the_twig_component(): void
    {
        $html = $this->render(
            '<twig:Ds:PageHeader title="A" description="B"><twig:block name="actions">x</twig:block></twig:Ds:PageHeader>'
            .'<twig:Ds:PageHeader variant="workshop" title="C" />',
        );

        $jsx = self::componentJsx('PageHeader');

        $variants = [];
        foreach (new Catalog()->entries() as $entry) {
            if ('PageHeader' === $entry->name) {
                $variants = $entry->variants;
            }
        }
        self::assertNotEmpty($variants);
        $expected = [];
        foreach ($this->classNames($jsx) as $class) {
            if (str_ends_with($class, '--')) {
                foreach ($variants as $variant) {
                    $expected[] = $class.$variant;
                }
            } else {
                $expected[] = $class;
            }
        }
        $actual = $this->classNames($html);
        sort($expected);
        sort($actual);

        self::assertSame($actual, $expected);
    }
}
