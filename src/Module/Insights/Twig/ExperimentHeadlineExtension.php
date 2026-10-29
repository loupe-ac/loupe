<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Insights\View\ExperimentHeadlineText;
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
        return [new TwigFunction('experiment_headline_text', $this->text(...))];
    }

    /** Symfony sets the default locale from the request, as format_currency reads it. */
    public function text(): ExperimentHeadlineText
    {
        return new ExperimentHeadlineText($this->translator, \Locale::getDefault());
    }
}
