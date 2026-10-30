<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\TemplateValueKey;
use App\Module\Workflow\Expression\NamedFact;

/** The named facts of one template while the parser reads it. It holds the sources, the facts resolved so far and the names being resolved. */
final class NamedFacts
{
    /** @var array<string, ?NamedFact> each fact by its name, null when its expression has an error */
    public array $resolved = [];

    /** @var list<string> the names being resolved, outermost first */
    public array $stack = [];

    /**
     * @param array<string, mixed>    $sources   the expression of each fact by its name, as the template writes it
     * @param ?list<TemplateValueKey> $valueKeys the value keys the template declares, or null when the rules have no template to check against
     */
    public function __construct(
        public readonly array $sources,
        public readonly ?array $valueKeys = null,
    ) {
    }
}
