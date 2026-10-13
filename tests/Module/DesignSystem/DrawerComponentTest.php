<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class DrawerComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_each_block_with_content_draws_its_band_in_order(): void
    {
        $html = $this->render(<<<'TWIG'
            <twig:Ds:Drawer data-modal-target="dialog">
                <twig:block name="header"><h2>Title</h2></twig:block>
                <twig:block name="body"><p>Body text</p></twig:block>
                <twig:block name="footer"><span>Action</span></twig:block>
            </twig:Ds:Drawer>
            TWIG);

        self::assertStringContainsString('<dialog class="lp-drawer" data-modal-target="dialog">', $html);
        self::assertMatchesRegularExpression('~lp-drawer__header">\s*<h2>Title</h2>\s*</header>\s*<div class="lp-drawer__body">\s*<p>Body text</p>\s*</div>\s*<footer class="lp-drawer__footer">\s*<span>Action</span>~', $html);
    }

    public function test_a_block_left_out_draws_no_band(): void
    {
        $html = $this->render('<twig:Ds:Drawer><twig:block name="body">Only the body</twig:block></twig:Ds:Drawer>');

        self::assertStringContainsString('lp-drawer__body', $html);
        self::assertStringNotContainsString('lp-drawer__header', $html);
        self::assertStringNotContainsString('lp-drawer__footer', $html);
    }

    public function test_the_content_block_replaces_the_three_bands(): void
    {
        $html = $this->render('<twig:Ds:Drawer><twig:block name="content"><turbo-frame id="f"></turbo-frame></twig:block></twig:Ds:Drawer>');

        self::assertStringContainsString('<turbo-frame id="f"></turbo-frame>', $html);
        self::assertStringNotContainsString('lp-drawer__body', $html);
    }

    public function test_a_caller_class_joins_the_root_class(): void
    {
        self::assertStringContainsString('class="lp-drawer lp-run-drawer"', $this->render('<twig:Ds:Drawer class="lp-run-drawer" />'));
    }
}
