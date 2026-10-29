<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Bridge\Metric\Metric;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The name a person reads for an experiment metric, on the Comparison tab and in experiment_get. */
final readonly class ExperimentMetricName
{
    private const array NAMED = [Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls, Metric::HarnessEvents];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function of(ExperimentMetric $metric): string
    {
        return $metric->label ?? $this->named($metric->key) ?? $this->translator->trans('bridge.experiments.metric.'.$metric->key);
    }

    /** The name of a key that names a bucket or a harness event, such as "Harness events: diagnostics". Null for any other key. */
    public function named(string $key): ?string
    {
        foreach (self::NAMED as $family) {
            if (str_starts_with($key, $family->value.':')) {
                return $this->translator->trans('analytics.metrics.metric.'.str_replace('-', '_', $family->value).'_named', [Metric::HarnessEvents === $family ? '%kind%' : '%bucket%' => substr($key, \strlen($family->value) + 1)]);
            }
        }

        return null;
    }
}
