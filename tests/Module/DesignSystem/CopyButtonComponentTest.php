<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class CopyButtonComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_the_wrapper_carries_the_controller_and_the_button_one_action_spelling(): void
    {
        $html = $this->render('<twig:Ds:CopyButton text="abc" label="Copy" copiedLabel="Copied!" />');

        self::assertStringContainsString('class="lp-copy-button lp-copy-button--text" data-controller="clipboard" data-clipboard-text-value="abc" data-clipboard-copied-label-value="Copied!"', $html);
        self::assertStringContainsString('data-clipboard-target="button" data-action="click->clipboard#copy">Copy</button>', $html);
        self::assertStringContainsString('class="lp-btn lp-btn--ghost lp-btn--sm"', $html);
    }

    public function test_the_chrome_tone_draws_the_pill_of_a_code_block(): void
    {
        $html = $this->render('<twig:Ds:CopyButton text="abc" label="Copy" tone="chrome" />');

        self::assertStringContainsString('class="lp-copy-button__chip"', $html);
        self::assertStringNotContainsString('lp-btn', $html);
        self::assertStringNotContainsString('data-clipboard-copied-label-value', $html);
    }

    public function test_the_button_variant_and_size_can_be_cleared(): void
    {
        $html = $this->render('<twig:Ds:CopyButton text="abc" label="Copy" buttonVariant="" buttonSize="" />');

        self::assertStringContainsString('class="lp-btn"', $html);
    }

    public function test_the_icon_variant_draws_both_icons_and_a_label_for_screen_readers(): void
    {
        $html = $this->render('<twig:Ds:CopyButton variant="icon" text="abc" label="Copy link" class="x" data-artifact-copy-link />');

        self::assertStringContainsString('lp-copy-button--icon', $html);
        self::assertStringContainsString('lp-copy-button__idle', $html);
        self::assertStringContainsString('lp-copy-button__done', $html);
        self::assertStringContainsString('class="x"', $html);
        self::assertStringContainsString('title="Copy link"', $html);
        self::assertStringContainsString('data-artifact-copy-link', $html);
        self::assertStringContainsString('aria-live="polite" data-clipboard-target="label">Copy link</span>', $html);
    }

    public function test_the_text_is_escaped_in_the_attribute(): void
    {
        $html = $this->render('<twig:Ds:CopyButton text=\'<script src="x"></script>\' label="Copy" />');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script', $html);
    }
}
