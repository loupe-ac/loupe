<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The `status` and `cardId` query values of a feedback list. */
final readonly class FeedbackFilter
{
    public const string DEFAULT_STATUS = 'pending';
    public const string ALL = 'all';

    private function __construct(
        /** The value as the request spells it, which a cursor repeats. */
        public string $statusValue,
        public ?SiteReviewCommentStatus $status,
        public ?Uuid $cardId,
    ) {
    }

    /** @throws ApiProblemException `validation_failed` for a status or a card id that the list does not know */
    public static function fromRequest(Request $request): self
    {
        $query = $request->query->all();
        $status = $query['status'] ?? self::DEFAULT_STATUS;
        $cardId = $query['cardId'] ?? null;

        $errors = [];
        if (!\is_string($status) || (self::ALL !== $status && null === SiteReviewCommentStatus::tryFrom($status))) {
            $errors[] = ['field' => 'status', 'code' => 'status_invalid', 'message' => 'The status must be pending, addressed, resolved or all.'];
        }
        if (null !== $cardId && (!\is_string($cardId) || !Uuid::isValid($cardId))) {
            $errors[] = ['field' => 'cardId', 'code' => 'card_id_invalid', 'message' => 'The cardId must be a card id.'];
        }
        if ([] !== $errors || !\is_string($status)) {
            throw ApiProblemException::validationFailed($errors);
        }

        return new self($status, self::ALL === $status ? null : SiteReviewCommentStatus::from($status), \is_string($cardId) ? Uuid::fromString($cardId) : null);
    }

    /** @return array<string, scalar|null> */
    public function filters(): array
    {
        return ['status' => $this->statusValue, 'cardId' => $this->cardId?->toRfc4122()];
    }
}
