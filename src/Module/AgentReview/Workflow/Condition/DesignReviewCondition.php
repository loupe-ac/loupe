<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow\Condition;

use App\Module\AgentReview\Workflow\DocumentReviewFacts;
use App\Module\AgentReview\Workflow\DocumentReviewState;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use App\Module\Workflow\Contract\ReadsDocumentTag;
use Symfony\Component\Translation\TranslatableMessage;

/** A condition that is true when one draft document with the tag is in a given state of its review loop. */
abstract readonly class DesignReviewCondition implements Condition, ReadsDocumentTag
{
    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.agent_review';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('tag', ParameterType::String), new Parameter('rounds', ParameterType::Int, min: 1)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [DocumentReviewFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return [] !== $this->matching($facts, $params);
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

    /** Whether the document is in the state the condition names. */
    abstract protected function matches(DocumentReviewState $document, int $rounds): bool;

    /**
     * @param array<string, mixed> $params
     *
     * @return list<DocumentReviewState>
     */
    protected function matching(Facts $facts, array $params): array
    {
        $rounds = ParameterValue::int($params, 'rounds');

        return array_values(array_filter(
            $facts->get(DocumentReviewFacts::class)->withTag(ParameterValue::string($params, 'tag')),
            fn (DocumentReviewState $document): bool => $this->matches($document, $rounds),
        ));
    }
}
