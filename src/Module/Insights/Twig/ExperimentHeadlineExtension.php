<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Insights\View\ExperimentHeadlineText;
use App\Module\Insights\View\ExperimentResultRows;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ExperimentHeadlineExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('experiment_headline_text', $this->text(...)),
            new TwigFunction('experiment_result_rows', $this->resultRows(...)),
        ];
    }

    /** Symfony sets the default locale from the request, as format_currency reads it. */
    public function text(): ExperimentHeadlineText
    {
        return new ExperimentHeadlineText($this->translator, \Locale::getDefault());
    }

    /** The result rows of a headline, best change first. Null when the headline has no baseline or a goal problem. */
    public function resultRows(ExperimentHeadline $headline): ?ExperimentResultRows
    {
        return ExperimentResultRows::of($headline, sorted: true);
    }
}
