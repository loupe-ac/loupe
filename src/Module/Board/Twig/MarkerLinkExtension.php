<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\MarkerLink;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The address of the page that a marker links to under its remedy. */
final class MarkerLinkExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,

        #[Autowire(param: 'app.docs_url')]
        private readonly string $docsUrl,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('marker_link_href', $this->href(...)),
        ];
    }

    public function href(MarkerLink $link, Project $project): string
    {
        return match ($link) {
            MarkerLink::Agents => $this->urls->generate('app_project_agents', ['id' => $project->requireId()]),
            MarkerLink::RulesetSetup => rtrim($this->docsUrl, '/').'/using/workflows/#set-up-the-ruleset',
        };
    }
}
