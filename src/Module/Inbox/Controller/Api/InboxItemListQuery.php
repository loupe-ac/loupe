<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Module\Inbox\Entity\InboxItemState;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The filters of an inbox list request, read from its query string. */
final readonly class InboxItemListQuery
{
    private function __construct(
        public ?InboxItemState $state,
        public ?Uuid $cardId,
        public ?Uuid $documentId,
        public ?Uuid $askId,
        public ?Uuid $sessionId,
        public ?string $query,
    ) {
    }

    /** @throws ApiProblemException `validation_failed` for a value that is not of the kind its name takes */
    public static function fromRequest(Request $request): self
    {
        $all = $request->query->all();
        $errors = [];
        $text = static function (string $name) use ($all, &$errors): ?string {
            if (!\array_key_exists($name, $all)) {
                return null;
            }
            if (!\is_string($all[$name]) || '' === trim($all[$name])) {
                $errors[] = ['field' => $name, 'code' => $name.'_invalid', 'message' => \sprintf('%s must be a non-empty text.', $name)];

                return null;
            }

            return trim($all[$name]);
        };
        $id = static function (string $name) use ($text, &$errors): ?Uuid {
            $value = $text($name);
            if (null === $value) {
                return null;
            }
            if (!Uuid::isValid($value)) {
                $errors[] = ['field' => $name, 'code' => $name.'_invalid', 'message' => \sprintf('%s must be an id.', $name)];

                return null;
            }

            return Uuid::fromString($value);
        };

        $state = null;
        $given = $text('state');
        if (null !== $given) {
            $state = InboxItemState::tryFrom($given);
            if (null === $state) {
                $errors[] = ['field' => 'state', 'code' => 'state_invalid', 'message' => 'The state must be one of: '.implode(', ', array_map(static fn (InboxItemState $case): string => $case->value, InboxItemState::cases())).'.'];
            }
        }

        $query = new self($state, $id('card'), $id('document'), $id('ask'), $id('session'), $text('q'));
        if ([] !== $errors) {
            throw ApiProblemException::validationFailed($errors);
        }

        return $query;
    }

    /**
     * What a cursor remembers, so a walk that changes a filter is refused.
     *
     * @return array<string, scalar|null>
     */
    public function filters(): array
    {
        return [
            'state' => $this->state?->value,
            'card' => $this->cardId?->toRfc4122(),
            'document' => $this->documentId?->toRfc4122(),
            'ask' => $this->askId?->toRfc4122(),
            'session' => $this->sessionId?->toRfc4122(),
            'q' => $this->query,
        ];
    }
}
