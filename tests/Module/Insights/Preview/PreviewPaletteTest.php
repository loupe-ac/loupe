<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use App\Module\Insights\Preview\PreviewPalette;
use PHPUnit\Framework\TestCase;

final class PreviewPaletteTest extends TestCase
{
    public function test_every_colour_matches_its_design_token(): void
    {
        $tokens = self::tokens();
        $expected = [
            'surface-1' => PreviewPalette::SURFACE,
            'text' => PreviewPalette::TEXT,
            'text-mute' => PreviewPalette::MUTED_TEXT,
            'surface-2' => PreviewPalette::SURFACE_2,
            'border' => PreviewPalette::BORDER,
            'border-strong' => PreviewPalette::BORDER_STRONG,
            'accent' => PreviewPalette::ACCENT,
            'chrome' => PreviewPalette::CHROME,
            'chart-other' => PreviewPalette::CHART_OTHER,
            'status-ok-ink' => PreviewPalette::STATUS_OK_INK,
            'status-ok-border' => PreviewPalette::STATUS_OK_BORDER,
            'status-pending-deep' => PreviewPalette::STATUS_PENDING_DEEP,
            'status-pending-bg' => PreviewPalette::STATUS_PENDING_BACKGROUND,
            'status-danger-deep' => PreviewPalette::STATUS_DANGER_DEEP,
            'status-danger-chip' => PreviewPalette::STATUS_DANGER_CHIP,
        ];
        foreach (PreviewPalette::CHART as $index => $colour) {
            $expected['chart-'.($index + 1)] = $colour;
        }

        self::assertCount(12, PreviewPalette::CHART);
        foreach ($expected as $token => $colour) {
            self::assertArrayHasKey($token, $tokens, \sprintf('tokens.css has no --%s.', $token));
            self::assertSame($tokens[$token], $colour, \sprintf('--%s differs from the palette.', $token));
        }
    }

    public function test_a_series_slot_picks_its_colour_and_slot_zero_is_other(): void
    {
        self::assertSame(PreviewPalette::CHART[0], PreviewPalette::series(1));
        self::assertSame(PreviewPalette::CHART[11], PreviewPalette::series(12));
        self::assertSame(PreviewPalette::CHART_OTHER, PreviewPalette::series(0));
    }

    /** @return array<string, string> */
    private static function tokens(): array
    {
        $css = file_get_contents(__DIR__.'/../../../../assets/styles/tokens.css');
        self::assertIsString($css);
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        preg_match_all('~--([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})\s*;~', $css, $matches, \PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[$match[1]] = strtolower($match[2]);
        }

        return $tokens;
    }
}
