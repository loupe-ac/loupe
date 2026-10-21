<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\CardPause;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\StoredMarker;
use Symfony\Component\Uid\Uuid;

/** A wait that a card has now, before the reconciler compares it with the stored waits. */
final readonly class WantedCardWait
{
    /**
     * @param array<string, string|int> $params
     * @param ?InboxCardWaitTrigger     $trigger  the switch that gates the wait, which only the waits of the previous sources carry
     * @param ?Document                 $document the document of a wait of the previous sources, which the item links
     */
    public function __construct(
        public string $markerKey,
        public string $label,
        public array $params,
        public MarkerSubject $subject,
        public \DateTimeImmutable $since,
        public ?InboxCardWaitTrigger $trigger = null,
        public ?Document $document = null,
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

    public function type(): InboxCardWaitType
    {
        return InboxCardWaitType::forSubject($this->subject->type);
    }

    public function key(): string
    {
        return InboxCardWait::computeKey($this->markerKey, $this->type(), $this->subject->ids);
    }

    public static function forPullRequestReady(Uuid $pullRequestId, string $headSha, \DateTimeImmutable $since = new \DateTimeImmutable()): self
    {
        return new self(
            'waits-for-approval',
            'marker.waits_for_approval',
            [],
            new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => $pullRequestId->toRfc4122(), 'head' => $headSha]),
            $since,
            InboxCardWaitTrigger::PullRequestReady,
        );
    }

    public static function forDocument(Document $document, int $versionNumber, \DateTimeImmutable $since = new \DateTimeImmutable()): self
    {
        return new self(
            'document-in-review',
            'marker.document_in_review',
            ['%title%' => $document->title],
            new MarkerSubject(MarkerSubjectType::Document, ['document' => $document->id?->toRfc4122() ?? throw new \LogicException('A stored document has an id.'), 'version' => (string) $versionNumber]),
            $since,
            InboxCardWaitTrigger::DocumentInReview,
            $document,
        );
    }

    /** A new pause of the card is a new wait, because the key names the pause. */
    public static function forPause(CardPause $pause, \DateTimeImmutable $since = new \DateTimeImmutable()): self
    {
        return new self(
            'paused',
            'marker.paused',
            ['%reason%' => $pause->reason],
            new MarkerSubject(MarkerSubjectType::CardPause, ['pause' => ($pause->id ?? throw new \LogicException('A stored pause has an id.'))->toRfc4122()]),
            $since,
            InboxCardWaitTrigger::CardPaused,
        );
    }

    public static function forRun(InboxCardWaitTrigger $trigger, Uuid $runId, \DateTimeImmutable $since = new \DateTimeImmutable()): self
    {
        if (!\in_array($trigger, [InboxCardWaitTrigger::RunBlocked, InboxCardWaitTrigger::RunGaveUp, InboxCardWaitTrigger::RunWaitingForPerson], true)) {
            throw new \InvalidArgumentException('A run wait has a run trigger.');
        }

        return new self(
            'run-needs-you',
            'marker.run_needs_you',
            [],
            new MarkerSubject(MarkerSubjectType::WorkerRun, ['run' => $runId->toRfc4122()]),
            $since,
            $trigger,
        );
    }
}
