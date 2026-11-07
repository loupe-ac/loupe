<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Insights\Service\PublicLinkUrl;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The public address of a link, for the share dialog. */
final class PublicLinkUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly PublicLinkUrl $urls,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_link_url', $this->urls->forCode(...)),
        ];
    }
}
