<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\View\ExperimentHeadlineText;
use App\Module\Insights\View\PublicExperimentResult;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The plain text line under a shared item: the preview description of a link, and the settings line of a graph. */
final readonly class PublicLinkDescription
{
    public const int NOTE_LENGTH = 200;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /** The settings of the graph, such as "Cost · Mean · Last 30 days · Day · Line". */
    public function forGraph(InsightsSavedGraph $graph): string
    {
        return implode(' · ', [
            $this->translator->trans('analytics.metrics.unit.'.$graph->unit->value),
            $this->translator->trans('analytics.metrics.statistic.'.$graph->statistic->value),
            $this->translator->trans('analytics.metrics.range.'.$graph->range->value),
            $this->translator->trans('analytics.metrics.bucket.'.$graph->bucket->value),
            $this->translator->trans('analytics.metrics.chart_type.'.str_replace('-', '_', $graph->chartType->value)),
        ]);
    }

    /** The note of the owner as plain text cut near 200 characters. Without a note, the goal and the variants. */
    public function forExperiment(PublicExperimentResult $result, ?string $noteHtml, string $projectName): string
    {
        $note = null === $noteHtml ? '' : self::plain($noteHtml);
        if ('' !== $note) {
            return self::cut($note, self::NOTE_LENGTH);
        }

        $headline = $result->headline;
        if (null === $headline) {
            return $this->translator->trans('insights.public_link.preview.experiment_line', ['%project%' => $projectName]);
        }

        $goal = $headline->goal;
        $metric = GoalState::UnknownMetric === $goal->problem
            ? $this->translator->trans('bridge.experiments.metric.'.ExperimentMetric::COST)
            : new ExperimentHeadlineText($this->translator, \Locale::getDefault())->metricName($goal->metric);
        if (null === $headline->baseline) {
            return $this->translator->trans('insights.public_link.preview.goal_line_no_baseline', ['%metric%' => $metric, '%count%' => \count($result->variants)]);
        }

        return $this->translator->trans('insights.public_link.preview.goal_line', ['%metric%' => $metric, '%count%' => \count($headline->comparisons), '%baseline%' => $headline->baseline]);
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), \ENT_QUOTES | \ENT_HTML5);

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /** Cuts at the last space that keeps the text inside the limit, with an ellipsis. */
    private static function cut(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $head = mb_substr($text, 0, $limit - 1);
        $space = mb_strrpos($head, ' ');
        if (false !== $space && ' ' !== mb_substr($text, $limit - 1, 1) && $space > $limit / 2) {
            $head = mb_substr($head, 0, $space);
        }

        return rtrim($head, ' ,;:.-').'…';
    }
}
