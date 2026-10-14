<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use PHPUnit\Framework\TestCase;

final class PanelStylesheetTest extends TestCase
{
    public function test_the_panel_draws_the_tint_of_the_old_tinted_cards_not_the_white_surface(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../assets/styles/components/panel.css');

        self::assertMatchesRegularExpression('/\.lp-panel \{\n\s*@apply [^;]*\bbg-surface-2\b/', $css);
        self::assertStringNotContainsString('bg-surface-1', $css);
    }
}
