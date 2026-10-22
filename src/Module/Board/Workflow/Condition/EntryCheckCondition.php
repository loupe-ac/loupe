<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow\Condition;

use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use App\Module\Workflow\Contract\ReadsDocumentTag;
use Symfony\Component\Translation\TranslatableMessage;

/** A condition over the linked documents with the tag. A marker that binds a document reads that document alone. */
abstract readonly class EntryCheckCondition implements Condition, ReadsDocumentTag
{
    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('tag', ParameterType::String)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [DocumentsFacts::class];
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage(
            ($negated ? 'workflow.waiting.not.' : 'workflow.waiting.').str_replace('.', '_', static::key()),
            ['%tag%' => ParameterValue::string($params, 'tag')],
        );
    }

    #[\Override]
    public function documentTag(array $params): string
    {
        return ParameterValue::string($params, 'tag');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<DocumentFacts>
     */
    protected function tagged(Facts $facts, array $params): array
    {
        $tag = ParameterValue::string($params, 'tag');

        return array_values(array_filter(
            $facts->get(DocumentsFacts::class)->documents,
            static fn (DocumentFacts $document): bool => \in_array($tag, $document->tags, true) && $facts->readsDocument($document->id),
        ));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<DocumentFacts>
     */
    protected function inReview(Facts $facts, array $params): array
    {
        return array_values(array_filter(
            $this->tagged($facts, $params),
            static fn (DocumentFacts $document): bool => DocumentStatus::InReview->value === $document->status,
        ));
    }
}
