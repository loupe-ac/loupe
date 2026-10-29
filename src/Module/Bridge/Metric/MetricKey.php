<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\BucketRule;
use App\Module\Workflow\Contract\TemplateMetricSet;

/**
 * A metric as an agent or a URL names it. It takes one of three forms: a built-in key such as `cost`,
 * `<family>:<name>` for a named metric such as `bucket-time:<name>` or `harness-events:<kind>`, or the key of a
 * metric that the workflow template declares.
 */
final readonly class MetricKey
{
    /** @param ?string $templateKey the key of a template metric, with no built-in metric and no name */
    public function __construct(
        public ?Metric $metric,
        public ?string $name = null,
        public ?string $templateKey = null,
    ) {
        if (null === $metric) {
            if (null === $templateKey || '' === $templateKey || null !== $name) {
                throw new \InvalidArgumentException('A key with no built-in metric names a template metric and takes no name.');
            }

            return;
        }
        if (null !== $templateKey) {
            throw new \InvalidArgumentException('A template key goes with no built-in metric.');
        }
        if ($metric->isNamed() !== (null !== $name)) {
            throw new \InvalidArgumentException(\sprintf('The metric %s takes a name only when it is a named metric.', $metric->value));
        }
        if (null !== $name && !self::isValidName($metric, $name)) {
            throw new \InvalidArgumentException(\sprintf('The name "%s" is not valid for the metric %s.', $name, $metric->value));
        }
    }

    public static function template(string $key): self
    {
        return new self(null, null, $key);
    }

    /** A built-in key wins, and a template may not reuse one. A template key must exist in the set. */
    public static function tryParse(string $key, TemplateMetricSet $templates): ?self
    {
        foreach (Metric::cases() as $metric) {
            $prefix = $metric->value.':';
            if ($metric->isNamed() && str_starts_with($key, $prefix)) {
                $name = substr($key, \strlen($prefix));

                return self::isValidName($metric, $name) ? new self($metric, $name) : null;
            }
        }

        $metric = Metric::tryFrom($key);
        if (null !== $metric) {
            return $metric->isNamed() ? null : new self($metric);
        }

        return null === $templates->metric($key) ? null : self::template($key);
    }

    public function key(): string
    {
        if (null !== $this->templateKey) {
            return $this->templateKey;
        }

        $metric = $this->metric ?? throw new \LogicException('A key has a built-in metric or a template key.');

        return null === $this->name ? $metric->value : $metric->value.':'.$this->name;
    }

    public function isTemplate(): bool
    {
        return null !== $this->templateKey;
    }

    private static function isValidName(Metric $metric, string $name): bool
    {
        return 1 === preg_match(match ($metric) {
            Metric::BucketTime, Metric::BucketTimeAll, Metric::BucketCalls => BucketRule::NAME_PATTERN,
            Metric::HarnessEvents => WorkerRun::HARNESS_EVENT_KIND_PATTERN,
            default => throw new \LogicException(\sprintf('The metric %s takes no name.', $metric->value)),
        }, $name);
    }
}
