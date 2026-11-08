<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

/**
 * The colours of a preview image. An image has no CSS, so the values repeat the design tokens
 * of assets/styles/tokens.css. PreviewPaletteTest fails when one drifts.
 */
final class PreviewPalette
{
    public const string SURFACE = '#ffffff';
    public const string TEXT = '#20241f';
    public const string MUTED_TEXT = '#555b52';
    public const string SURFACE_2 = '#f7f8f3';
    public const string BORDER = '#e1e5da';
    public const string BORDER_STRONG = '#cdd3c6';
    public const string ACCENT = '#d4e94c';
    public const string CHROME = '#181d18';
    public const string CHART_OTHER = '#898781';

    public const string STATUS_OK_INK = '#0f3d20';
    public const string STATUS_OK_BORDER = '#b8f2c8';
    public const string STATUS_PENDING_DEEP = '#7a5514';
    public const string STATUS_PENDING_BACKGROUND = '#fdf4e3';
    public const string STATUS_DANGER_DEEP = '#8c1e14';
    public const string STATUS_DANGER_CHIP = '#ffd9d5';

    /** The series colours in order, from chart-1 to chart-12. */
    public const array CHART = [
        '#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300',
        '#4a3aa7', '#e34948', '#0e7c8c', '#8c5a2b', '#a43fc4', '#1d3f73',
    ];

    /** @return array<string, string|list<string>> the colours by the names the preview templates read */
    public static function forTemplate(): array
    {
        return [
            'surface' => self::SURFACE,
            'surface2' => self::SURFACE_2,
            'text' => self::TEXT,
            'muted' => self::MUTED_TEXT,
            'border' => self::BORDER,
            'borderStrong' => self::BORDER_STRONG,
            'accent' => self::ACCENT,
            'chrome' => self::CHROME,
            'other' => self::CHART_OTHER,
            'chart' => self::CHART,
        ];
    }

    /** The colour of a series slot as MetricChartSeries numbers it. Slot 0 is the "other" bucket. */
    public static function series(int $slot): string
    {
        return self::CHART[$slot - 1] ?? self::CHART_OTHER;
    }
}
