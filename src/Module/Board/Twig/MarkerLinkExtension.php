<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use App\Module\Board\Service\CardMarkerText;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\MarkerLink;
use App\Module\Workflow\Contract\StoredMarker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** The address of the page that a marker links to under its remedy, and the words of a marker label. */
final class MarkerLinkExtension extends AbstractExtension
{
    /** The flag of the inbox. The inbox routes answer 404 while it is off. */
    private const string INBOX_FLAG = 'inbox.enabled';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly CardMarkerText $markerText,
        private readonly FeatureFlagService $featureFlags,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('marker_link_target', $this->target(...)),
            new TwigFunction('marker_link_href', $this->href(...)),
            new TwigFunction('card_marker_label', $this->label(...), ['is_safe' => ['html']]),
        ];
    }

    /** Markup, so the label stays HTML when a template passes it on as a component prop. */
    public function label(StoredMarker $marker): Markup
    {
        return new Markup($this->markerText->html($marker), 'UTF-8');
    }

    /** The link a marker shows. An inbox link falls back to the repositories page while the inbox is off. */
    public function target(MarkerLink $link): MarkerLink
    {
        return MarkerLink::Inbox === $link && !$this->featureFlags->isEnabled(self::INBOX_FLAG) ? MarkerLink::Repositories : $link;
    }

    public function href(MarkerLink $link, Project $project): string
    {
        return match ($this->target($link)) {
            MarkerLink::Agents => $this->urls->generate('app_project_agents', ['id' => $project->requireId()]),
            MarkerLink::Repositories => $this->urls->generate('app_project_repositories_settings', ['id' => $project->requireId()]),
            MarkerLink::Inbox => $this->urls->generate('app_project_inbox', ['id' => $project->requireId()]),
        };
    }
}
