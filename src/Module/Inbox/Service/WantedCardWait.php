<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\StoredMarker;

/** A wait that a card has now, before the reconciler compares it with the stored waits. */
final readonly class WantedCardWait
{
    /**
     * @param array<string, string|int> $params
     */
    public function __construct(
        public string $markerKey,
        public string $label,
        public array $params,
        public MarkerSubject $subject,
        public \DateTimeImmutable $since,
    ) {
    }

    /** A stored entry of the inbox set gives one wait. */
    public static function fromMarker(StoredMarker $marker): self
    {
        return new self(
            $marker->key,
            $marker->label,
            $marker->params,
            $marker->subject ?? throw new \InvalidArgumentException('An entry of the inbox set names its thing.'),
            $marker->since,
        );
    }

    /** @param array<string, string|int> $params */
    public function withParams(array $params): self
    {
        return new self($this->markerKey, $this->label, $params, $this->subject, $this->since);
    }

    public function type(): InboxCardWaitType
    {
        return InboxCardWaitType::forSubject($this->subject->type);
    }

    public function key(): string
    {
        return InboxCardWait::computeKey($this->markerKey, $this->type(), $this->subject->ids);
    }
}
