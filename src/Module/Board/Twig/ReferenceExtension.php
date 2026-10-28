<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use App\Module\Board\Reference\ReferenceLinker;
use App\Module\Project\Entity\Project;
use App\Module\Review\Service\ReferenceTokens;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Links the card and pull request references inside `data-references` regions, and shows tokens as plain text in titles. */
final class ReferenceExtension extends AbstractExtension
{
    public function __construct(
        private readonly ReferenceLinker $linker,
        private readonly ReferenceTokens $tokens,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('link_references', $this->link(...), ['is_safe' => ['html']]),
            new TwigFilter('reference_plain', $this->plain(...)),
        ];
    }

    public function link(string|\Stringable $html, Project $project): string
    {
        return $this->linker->link((string) $html, $project);
    }

    public function plain(string|\Stringable|null $title): string
    {
        return $this->tokens->plain((string) $title);
    }
}
