<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Insights\Service\InsightsRefreshSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** A waiting Dashboard tile reads how long it waits before it offers Retry. */
final class SlowNoticeExtension extends AbstractExtension
{
    public function __construct(
        private readonly InsightsRefreshSettings $settings,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('dashboard_slow_notice_seconds', $this->settings->slowNoticeSeconds(...)),
        ];
    }
}
