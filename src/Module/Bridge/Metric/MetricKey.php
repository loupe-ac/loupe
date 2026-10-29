<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use App\Module\Bridge\Service\BucketRule;
use App\Module\Workflow\Contract\TemplateMetricSet;

/**
 * A metric as an agent or a URL names it. It takes one of three forms: a built-in key such as `cost`,
 * `bucket-time:<name>` for the time in one bucket, or the key of a metric that the workflow template declares.
 */
final readonly class MetricKey
{
    /** @param ?string $templateKey the key of a template metric, with no built-in metric and no bucket name */
    public function __construct(
        public ?Metric $metric,
        public ?string $bucketName = null,
        public ?string $templateKey = null,
    ) {
        if (null === $metric) {
            if (null === $templateKey || '' === $templateKey || null !== $bucketName) {
                throw new \InvalidArgumentException('A key with no built-in metric names a template metric and takes no bucket name.');
            }

            return;
        }
        if (null !== $templateKey) {
            throw new \InvalidArgumentException('A template key goes with no built-in metric.');
        }
        if ((Metric::BucketTime === $metric) !== (null !== $bucketName)) {
            throw new \InvalidArgumentException(\sprintf('The metric %s takes a bucket name only when it is %s.', $metric->value, Metric::BucketTime->value));
        }
        if (null !== $bucketName && 1 !== preg_match(BucketRule::NAME_PATTERN, $bucketName)) {
            throw new \InvalidArgumentException(\sprintf('The bucket name "%s" is not 1 to 64 characters of a-z, 0-9, "_" and "-".', $bucketName));
        }
    }

    public static function template(string $key): self
    {
        return new self(null, null, $key);
    }

    /** A built-in key wins, and a template may not reuse one. A template key must exist in the set. */
    public static function tryParse(string $key, TemplateMetricSet $templates): ?self
    {
        $prefix = Metric::BucketTime->value.':';
        if (str_starts_with($key, $prefix)) {
            $name = substr($key, \strlen($prefix));

            return 1 === preg_match(BucketRule::NAME_PATTERN, $name) ? new self(Metric::BucketTime, $name) : null;
        }

        $metric = Metric::tryFrom($key);
        if (null !== $metric) {
            return Metric::BucketTime === $metric ? null : new self($metric);
        }

        return null === $templates->metric($key) ? null : self::template($key);
    }

    public function key(): string
    {
        if (null !== $this->templateKey) {
            return $this->templateKey;
        }

        $metric = $this->metric ?? throw new \LogicException('A key has a built-in metric or a template key.');

        return null === $this->bucketName ? $metric->value : $metric->value.':'.$this->bucketName;
    }

    public function isTemplate(): bool
    {
        return null !== $this->templateKey;
    }
}
