<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicLinkKind;
use App\Module\Insights\Repository\PublicLinkRepository;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** Decides whether a visitor may see the item behind a code. Every reason to hide it gives the same answer: nothing. */
final readonly class PublicLinkVisibility
{
    public function __construct(
        private PublicLinkRepository $publicLinks,
        private FeatureFlagService $featureFlags,
    ) {
    }

    /** The link when its item may show, or null for an unknown code, a switched-off link, a removed item and an instance with the flag off. */
    public function resolve(string $code): ?PublicLink
    {
        if (!$this->featureFlags->isEnabled(PublicLink::FLAG, true)) {
            return null;
        }
        $link = $this->publicLinks->findByCode($code);
        if (null === $link || !$link->enabled) {
            return null;
        }
        if (PublicLinkKind::Graph === $link->kind && null !== $link->savedGraph?->removedAt) {
            return null;
        }

        return $link;
    }
}
