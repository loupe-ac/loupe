<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The Dashboard page passes this topic to mercure_subscribe(). */
final class DashboardTopicExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProjectTopicBuilder $topics,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('dashboard_topic', $this->dashboardTopic(...)),
        ];
    }

    public function dashboardTopic(Project $project): string
    {
        return $this->topics->forDashboard($project->requireId());
    }
}
