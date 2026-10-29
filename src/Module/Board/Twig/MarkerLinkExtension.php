<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use App\Module\Board\Service\CardMarkerText;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\MarkerLink;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The address of the page that a marker links to under its remedy, and the words of a marker label. */
final class MarkerLinkExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly CardMarkerText $markerText,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('marker_link_href', $this->href(...)),
            new TwigFunction('card_marker_label', $this->markerText->label(...)),
        ];
    }

    public function href(MarkerLink $link, Project $project): string
    {
        return match ($link) {
            MarkerLink::Agents => $this->urls->generate('app_project_agents', ['id' => $project->requireId()]),
            MarkerLink::Repositories => $this->urls->generate('app_project_repositories_settings', ['id' => $project->requireId()]),
        };
    }
}
