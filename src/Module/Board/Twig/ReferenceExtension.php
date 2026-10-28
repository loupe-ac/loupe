<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use App\Module\Board\Reference\ReferenceLinker;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Links the card and pull request references inside the `data-references` regions of rendered HTML. */
final class ReferenceExtension extends AbstractExtension
{
    public function __construct(
        private readonly ReferenceLinker $linker,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('link_references', $this->link(...), ['is_safe' => ['html']]),
        ];
    }

    public function link(string|\Stringable $html, Project $project): string
    {
        return $this->linker->link((string) $html, $project);
    }
}
