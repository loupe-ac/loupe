<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use PHPUnit\Framework\TestCase;

final class AvatarStylesheetTest extends TestCase
{
    public function test_the_card_drawer_icon_keeps_its_own_box_after_the_source_avatar_rule_left(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../assets/styles/app.css');

        self::assertMatchesRegularExpression('/^\s*\.lp-card-drawer__icon \{\n\s*@apply [^;]*\bw-8 h-8\b/m', $css);
        self::assertDoesNotMatchRegularExpression('/\.lp-card-drawer__icon,/', $css);
    }
}
