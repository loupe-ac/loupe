<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Service\PublicShareBlock;

/** What a share dialog knows about the public link of one item, a graph or an experiment: the link, if the owner ever shared it, and any reason that blocks a share. */
final readonly class PublicLinkShareView
{
    public function __construct(
        public ?PublicLink $link,
        public ?PublicShareBlock $block,
    ) {
    }

    /** False when the instance switched sharing off, which hides the Share action. */
    public function isAvailable(): bool
    {
        return PublicShareBlock::FlagOff !== $this->block;
    }

    /** The owner's switch, whatever blocks it now. */
    public function isEnabled(): bool
    {
        return null !== $this->link && $this->link->enabled;
    }

    /** True when a visitor can open the link. */
    public function isPublic(): bool
    {
        return $this->isEnabled() && null === $this->block;
    }
}
