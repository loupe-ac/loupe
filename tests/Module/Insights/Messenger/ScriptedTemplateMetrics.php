<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Messenger;

use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateMetrics;
use App\Module\Workflow\Contract\TemplateMetricSet;
use Symfony\Component\Uid\Uuid;

/** Answers the first read with a template that declares `rework`, and each later read with the next set of `$later`, or with an empty template. */
final class ScriptedTemplateMetrics implements TemplateMetrics
{
    public int $calls = 0;

    /** The set of the first read, in place of the `rework` template. */
    public ?TemplateMetricSet $first = null;

    /** @var list<TemplateMetricSet> */
    public array $later = [];

    public function forProject(Uuid $projectId): TemplateMetricSet
    {
        $set = 0 === $this->calls ? $this->first ?? self::declaring('Before') : $this->later[$this->calls - 1] ?? new TemplateMetricSet();
        ++$this->calls;

        return $set;
    }

    /** A card metric that runs from the arrival of a card in the `review` slot to its departure, with the slot at `$column`. */
    public static function reviewTime(?Uuid $column): TemplateMetricSet
    {
        return new TemplateMetricSet(
            [new TemplateMetric('review-time', 'Review time', 'Hours in review.', MetricType::Duration, from: new DurationEvent(DurationEvent::ARRIVED, 'review'), to: new DurationEvent(DurationEvent::LEFT, 'review'))],
            [],
            ['review' => $column],
        );
    }

    public static function declaring(string $label): TemplateMetricSet
    {
        return new TemplateMetricSet([new TemplateMetric('rework', $label, 'Fix rounds.', MetricType::Count, filter: new CountFilter(role: 'fix'))]);
    }
}
