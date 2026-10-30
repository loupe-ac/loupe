<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Insights\View\MetricChartSpecimen;
use App\Module\Insights\View\MetricChartType;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Gives the styleguide the fixed layout and labels of one chart type. */
final class MetricChartSpecimenExtension extends AbstractExtension
{
    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('metric_chart_specimen', static fn (string $type): array => MetricChartSpecimen::of(MetricChartType::from($type)))];
    }
}
