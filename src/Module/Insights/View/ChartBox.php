<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

/** The view box of a chart and the plot box inside it, in view-box units. */
final readonly class ChartBox
{
    public function __construct(
        public int $width = MetricChartScale::WIDTH,
        public int $height = MetricChartScale::HEIGHT,
        public int $plotLeft = MetricChartScale::PLOT_LEFT,
        public int $plotRight = MetricChartScale::PLOT_RIGHT,
        public int $plotTop = MetricChartScale::PLOT_TOP,
        public int $baseline = MetricChartScale::BASELINE,
    ) {
    }

    /** The box of a chart drawn into a preview image. */
    public static function preview(): self
    {
        return new self(width: 1120, height: 480, plotLeft: 72, plotRight: 1096, plotTop: 24, baseline: 436);
    }

    public function plotHeight(): int
    {
        return $this->baseline - $this->plotTop;
    }

    public function plotWidth(): int
    {
        return $this->plotRight - $this->plotLeft;
    }
}
