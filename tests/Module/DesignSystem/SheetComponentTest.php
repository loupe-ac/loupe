<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class SheetComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_it_draws_the_handle_the_header_and_the_body_in_order(): void
    {
        $html = $this->render('<twig:Ds:Sheet title="More"><p>Body text</p></twig:Ds:Sheet>');

        self::assertStringContainsString('<dialog class="lp-sheet" aria-label="More" data-sheet-target="dialog" data-action="cancel-&gt;sheet#close">', $html);
        self::assertMatchesRegularExpression('~lp-sheet__handle" aria-hidden="true"></div>\s*<header class="lp-sheet__header">.*<h2 class="lp-sheet__title">More</h2>.*</header>\s*<div class="lp-sheet__body"><p>Body text</p></div>~s', $html);
    }

    public function test_the_back_button_starts_hidden_and_the_close_button_closes(): void
    {
        $html = $this->render('<twig:Ds:Sheet title="More" />');

        self::assertMatchesRegularExpression('~<button[^>]*\bhidden\b[^>]*data-sheet-target="back"|<button[^>]*data-sheet-target="back"[^>]*\bhidden\b~', $html);
        self::assertStringContainsString('click-&gt;sheet#back', $html);
        self::assertStringContainsString('click-&gt;sheet#close', $html);
    }

    public function test_the_buttons_take_translated_default_labels(): void
    {
        $html = $this->render('<twig:Ds:Sheet title="More" />');

        self::assertStringContainsString('aria-label="Back"', $html);
        self::assertStringContainsString('aria-label="Close"', $html);
    }

    public function test_a_caller_label_and_class_replace_the_defaults(): void
    {
        $html = $this->render('<twig:Ds:Sheet title="More" closeLabel="Close menu" class="lp-review-sheet" />');

        self::assertStringContainsString('aria-label="Close menu"', $html);
        self::assertStringContainsString('class="lp-sheet lp-review-sheet"', $html);
    }
}
