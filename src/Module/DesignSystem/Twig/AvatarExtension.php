<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * The letter an avatar shows. The Avatar component calls it, and so does a
 * template that draws its own tag around a name.
 */
final class AvatarExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [new TwigFilter('ds_initial', $this->initial(...))];
    }

    /** The first user-perceived character, so a combined or non-Latin letter stays whole. */
    public function initial(?string $name): string
    {
        if (1 !== preg_match('/\X/u', trim((string) $name), $match)) {
            return '';
        }

        return mb_strtoupper($match[0]);
    }
}
