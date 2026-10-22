<?php

declare(strict_types=1);

namespace App\Twig;

use App\Site\SiteLinks;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The outside links of the site, each empty when off. */
final class SiteLinksExtension extends AbstractExtension
{
    public function __construct(
        private readonly SiteLinks $links,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_discord_url', $this->links->discordUrl(...)),
            new TwigFunction('site_x_url', $this->links->xUrl(...)),
            new TwigFunction('site_source_url', $this->links->sourceUrl(...)),
        ];
    }
}
