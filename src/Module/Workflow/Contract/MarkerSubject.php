<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * The thing a stored marker entry names, such as a pull request and its head commit.
 * Two entries of one marker are the same entry when their subjects are equal.
 */
final readonly class MarkerSubject
{
    /** @param array<string, string> $ids the ids that tell this thing from another of its type, such as `pullRequest` and `head` */
    public function __construct(
        public MarkerSubjectType $type,
        public array $ids,
    ) {
    }

    /** The subject of a marker that names no single thing: one spell of the marker on the card. */
    public static function card(Uuid $cardId, \DateTimeImmutable $spellStart): self
    {
        return new self(MarkerSubjectType::Card, [
            'card' => $cardId->toRfc4122(),
            'spell' => $spellStart->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ]);
    }

    /** The subject of a marker about a document: the document and its newest version. */
    public static function document(DocumentFacts $document): self
    {
        return new self(MarkerSubjectType::Document, ['document' => $document->id, 'version' => (string) ($document->version ?? 0)]);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->ids == $other->ids;
    }

    /** @return array{type: string, ids: array<string, string>} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'ids' => $this->ids];
    }

    /** @param array{type: string, ids: array<string, string>} $entry */
    public static function fromArray(array $entry): self
    {
        return new self(MarkerSubjectType::from($entry['type']), $entry['ids']);
    }
}
