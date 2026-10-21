<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\DocumentStatuses;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ListsDocuments;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\NamesMarkerSubject;
use App\Module\Workflow\Contract\ReadsVerdict;
use App\Module\Workflow\Service\VerdictWouldFire;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * A document in review that carries a tag of the slot of the card, and its approval would make a rule of that slot fire.
 * A marker that binds a document asks about that document alone, and the rules still read every document.
 */
final readonly class CardDocumentApprovalMoves implements Condition, MarkerEvidence, NamesMarkerSubject, ReadsVerdict
{
    public function __construct(
        #[Autowire(service: VerdictWouldFire::class, lazy: true)]
        private VerdictWouldFire $verdict,

        #[Autowire(service: TemplateSource::class, lazy: true)]
        private TemplateSource $templates,
    ) {
    }

    #[\Override]
    public static function key(): string
    {
        return 'card.document.approval_moves';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.workflow';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [EngineFact::Slot, ListsDocuments::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return [] !== $this->moving($facts);
    }

    /** The first document whose approval would move the card gives the evidence. */
    #[\Override]
    public function evidence(Facts $facts, array $params): ?Evidence
    {
        $first = $this->moving($facts)[0] ?? null;

        return null === $first ? null : new Evidence(['%title%' => $first->title ?? ''], MarkerSubject::document($first));
    }

    #[\Override]
    public function subjectType(): MarkerSubjectType
    {
        return MarkerSubjectType::Document;
    }

    /** @return list<DocumentFacts> */
    private function moving(Facts $facts): array
    {
        if (null === $facts->projectId) {
            return [];
        }
        try {
            $template = $this->templates->forProject($facts->projectId);
        } catch (TemplateMissing) {
            return [];
        }

        $tags = $template->documentTagsFor($facts->slot);
        $candidates = [];
        foreach ($facts->get(ListsDocuments::class)->documents as $document) {
            if (DocumentStatuses::IN_REVIEW === $document->status && self::carriesTag($document, $tags) && $facts->readsDocument($document->id)) {
                $candidates[$document->id] = $document;
            }
        }
        if ([] === $candidates) {
            return [];
        }
        $moving = $this->verdict->forFacts($facts->withDocument(null), $template, array_keys($candidates), [DocumentStatuses::APPROVED]);

        return array_values(array_intersect_key($candidates, array_flip($moving)));
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_document_approval_moves' : 'workflow.waiting.card_document_approval_moves');
    }

    /** @param list<string> $tags */
    private static function carriesTag(DocumentFacts $document, array $tags): bool
    {
        return [] !== array_intersect($tags, $document->tags);
    }
}
