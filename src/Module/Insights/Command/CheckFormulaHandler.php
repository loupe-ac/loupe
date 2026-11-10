<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Workflow\Contract\TemplateMetrics;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CheckFormulaHandler
{
    public function __construct(
        private TemplateMetrics $templateMetrics,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(CheckFormulaCommand $command): FormulaCheckResult
    {
        $formula = MetricFormula::parse($command->rows, $command->formula, $command->format, $this->templateMetrics->forProject($command->project->requireId()));
        $problem = $formula->problem;
        if (null === $problem) {
            return new FormulaCheckResult(true);
        }
        $text = $this->translator->trans($problem->key, $problem->parameters);

        return new FormulaCheckResult(false, $problem->position > 0 ? $this->translator->trans('analytics.metrics.formula.at_character', ['%message%' => $text, '%position%' => $problem->position]) : $text);
    }
}
