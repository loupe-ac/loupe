<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Entity;

/** The answer of a fix round to the finding at one position of a document review. */
final readonly class DocumentReviewFindingAnswer
{
    public function __construct(
        public int $index,
        public DocumentReviewAnswer $answer,
        public ?string $reason = null,
    ) {
    }

    /**
     * @param array{index: int, answer: string, reason: ?string} $answer
     */
    public static function fromArray(array $answer): self
    {
        return new self($answer['index'], DocumentReviewAnswer::from($answer['answer']), $answer['reason']);
    }

    /**
     * @return array{index: int, answer: string, reason: ?string}
     */
    public function toArray(): array
    {
        return ['index' => $this->index, 'answer' => $this->answer->value, 'reason' => $this->reason];
    }
}
