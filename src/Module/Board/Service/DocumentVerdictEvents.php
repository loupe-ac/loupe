<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\Actor;
use App\Security\ApiKeyActor;

/** Writes the `document-verdict` row of each card that a document hangs off. The caller's transaction flushes the rows. */
final readonly class DocumentVerdictEvents
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private CardEventRepository $cardEvents,
    ) {
    }

    /** @param array<string, string> $detail what the row adds to the rule, the document and its id */
    public function record(Document $document, ?string $rule, Actor $actor, ?User $person, array $detail, \DateTimeImmutable $at, ?ApiKeyActor $apiKey = null): void
    {
        foreach ($this->cardDocuments->findForDocument($document) as $link) {
            $this->cardEvents->record($link->card, CardEventKind::DocumentVerdict, $actor, $person, [
                'rule' => $rule,
                'document' => $document->title,
                'documentId' => (string) $document->id,
            ] + $detail, $at, $apiKey);
        }
    }
}
