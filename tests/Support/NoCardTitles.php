<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Module\Project\Entity\Project;

/** A card title source that holds no card. */
final readonly class NoCardTitles implements CardTitleSourceInterface
{
    #[\Override]
    public function titlesFor(Project $project, array $cardIds): array
    {
        return [];
    }
}
