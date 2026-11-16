<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Messenger;

use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Module\Workflow\Contract\TemplateMetricSet;
use Symfony\Component\Uid\Uuid;

/** Answers the first read with a template that declares `rework`, and each later read with the next set of `$later`, or with an empty template. */
final class ScriptedTemplateMetrics implements TemplateMetrics
{
    public int $calls = 0;

    /** @var list<TemplateMetricSet> */
    public array $later = [];

    public function forProject(Uuid $projectId): TemplateMetricSet
    {
        $set = 0 === $this->calls ? self::declaring('Before') : $this->later[$this->calls - 1] ?? new TemplateMetricSet();
        ++$this->calls;

        return $set;
    }

    public static function declaring(string $label): TemplateMetricSet
    {
        return new TemplateMetricSet([new TemplateMetric('rework', $label, 'Fix rounds.', MetricType::Count, filter: new CountFilter(role: 'fix'))]);
    }
}
