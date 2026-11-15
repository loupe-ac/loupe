<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The Analytics pages pass this topic to mercure_subscribe(). */
final class AnalyticsTopicExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProjectTopicBuilder $topics,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('analytics_topic', $this->analyticsTopic(...)),
        ];
    }

    public function analyticsTopic(Project $project): string
    {
        return $this->topics->forAnalytics($project->requireId());
    }
}
