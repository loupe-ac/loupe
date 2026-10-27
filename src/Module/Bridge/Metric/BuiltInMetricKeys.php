<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Workflow\Contract\ReservedMetricKeys;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** The keys of the built-in metrics, which a workflow template may not declare again. */
#[AsAlias(ReservedMetricKeys::class)]
final readonly class BuiltInMetricKeys implements ReservedMetricKeys
{
    #[\Override]
    public function keys(): array
    {
        return array_map(static fn (Metric $metric): string => $metric->value, Metric::cases());
    }
}
