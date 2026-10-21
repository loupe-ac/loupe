<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\DocumentStatuses;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ListsDocuments;
use App\Module\Workflow\Contract\ReadsVerdict;
use App\Module\Workflow\Service\VerdictWouldFire;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatableMessage;

/** A document in review that carries a tag of the slot of the card, and a verdict on it would make a rule of that slot fire. */
final readonly class CardDocumentApprovalMoves implements Condition, ReadsVerdict
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
        return [EngineFact::Slot];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        if (null === $facts->projectId) {
            return false;
        }
        try {
            $template = $this->templates->forProject($facts->projectId);
        } catch (TemplateMissing) {
            return false;
        }

        $tags = $template->documentTagsFor($facts->slot);
        $ids = [];
        foreach ($facts->provided as $provided) {
            if ($provided instanceof ListsDocuments) {
                foreach ($provided->documents as $document) {
                    if (DocumentStatuses::IN_REVIEW === $document->status && self::carriesTag($document, $tags)) {
                        $ids[] = $document->id;
                    }
                }
            }
        }

        return [] !== $ids && [] !== $this->verdict->forFacts($facts, $template, $ids);
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
