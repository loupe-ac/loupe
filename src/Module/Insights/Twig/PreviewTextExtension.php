<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Insights\Preview\PreviewText;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class PreviewTextExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [new TwigFilter('preview_fit', PreviewText::fit(...))];
    }
}
