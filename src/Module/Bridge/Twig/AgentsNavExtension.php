<?php

declare(strict_types=1);

namespace App\Module\Bridge\Twig;

use App\Module\Bridge\Service\AgentsFiguresReader;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The busy and maximum workers of a project, for the pill of the Agents link in the sidebar. */
final class AgentsNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly AgentsFiguresReader $figures,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('project_agents_figures', $this->figures->forProject(...))];
    }
}
