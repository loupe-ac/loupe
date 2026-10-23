<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;

/** A linked document with the tag ever got a change request or a withdrawal, on any version and in any status. */
final readonly class CardDocumentRejectedBefore extends EntryCheckCondition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document.rejected_before';
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return array_any(
            $this->tagged($facts, $params),
            static fn (DocumentFacts $document): bool => $document->rejectedBefore,
        );
    }
}
