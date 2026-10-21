<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Workflow\Contract\CardMarkerStore;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\NamesMarkerSubject;
use App\Module\Workflow\Contract\SlotKeys;
use App\Module\Workflow\Contract\StoredMarker;
use App\Module\Workflow\Contract\TimedCondition;
use App\Module\Workflow\Template\TemplateMarker;

/**
 * Finds the markers of a card in the facts of the pass and stores them on the card.
 * It runs for every card the engine reads, held or not, and it changes nothing but the stored set.
 */
final readonly class MarkerPass
{
    public function __construct(
        private RuleSubject $ruleSubject,
        private CardMarkerStore $store,
    ) {
    }

    public function run(Evaluation $run): void
    {
        $markers = [];
        $inbox = [];
        $dueAt = null;
        if (SlotKeys::TERMINAL !== $run->facts->slot) {
            $stored = [];
            foreach ($this->store->read($run->card->id) as $marker) {
                $stored[$marker->key] = $marker;
            }
            [$markers, $inbox, $dueAt] = $this->find($run, $stored, $this->store->readInbox($run->card->id));
        }

        $this->store->write($run->card->projectId, $run->card->id, $markers, $inbox, $dueAt);
    }

    /** Stores an empty set for a card that has no template to read markers from. */
    public function clear(CardSnapshot $card): void
    {
        $this->store->write($card->projectId, $card->id, [], [], null);
    }

    /**
     * @param array<string, StoredMarker> $stored      the board set before this pass, by key
     * @param ?list<StoredMarker>         $storedInbox the inbox set before this pass, or null when no pass of this version read the card yet
     *
     * @return array{list<StoredMarker>, list<StoredMarker>, ?\DateTimeImmutable} the board set in display order, the inbox set, and the earliest time a timed condition changes
     */
    private function find(Evaluation $run, array $stored, ?array $storedInbox): array
    {
        $matched = [];
        $inbox = [];
        $fallback = null;
        $stateMatched = false;
        $dueAt = null;
        foreach ($run->template->markers as $marker) {
            if (!self::applies($marker, $run->facts->slot) || null !== $marker->when->unreadable($run->facts)) {
                continue;
            }
            foreach ($this->ruleSubject->subjects($marker->when, $run->facts) as $subject) {
                $dueAt = self::earlier($dueAt, $this->changesAt($marker, $subject));
            }
            $bound = $this->ruleSubject->bindWhen($marker->when, $run->facts);
            if (!$bound->truth) {
                continue;
            }
            $entry = $this->entry($marker, $bound->facts, $stored[$marker->key] ?? null, $run->now)->withoutSubject();
            if (\in_array($marker->key, $run->template->inbox, true)) {
                $entries = $this->inboxEntries($run, $marker, $bound->facts, $stored[$marker->key] ?? null, $storedInbox);
                if ([] !== $entries) {
                    $inbox = [...$inbox, ...$entries];
                    $entry = $entry->withSince($entries[0]->since);
                }
            }
            if ($marker->fallback) {
                $fallback ??= $entry;
                continue;
            }
            $stateMatched = $stateMatched || MarkerKind::Chip !== $marker->kind;
            $matched[] = $entry;
        }
        if (!$stateMatched && null !== $fallback) {
            $matched[] = $fallback;
        }

        $order = array_flip(array_map(static fn (MarkerKind $kind): string => $kind->value, MarkerKind::cases()));
        // The sort is stable, so the template order holds inside one kind.
        usort($matched, static fn (StoredMarker $a, StoredMarker $b): int => $order[$a->kind->value] <=> $order[$b->kind->value]);

        return [$matched, $inbox, $dueAt];
    }

    /**
     * One entry for each thing that makes a listed marker true. A marker whose leaves name no subject type, or two, has no single thing,
     * so it gets one entry for the card, which stands for one spell of the marker.
     *
     * @param ?list<StoredMarker> $storedInbox
     *
     * @return list<StoredMarker>
     */
    private function inboxEntries(Evaluation $run, TemplateMarker $marker, Facts $bound, ?StoredMarker $board, ?array $storedInbox): array
    {
        $type = self::subjectType($marker);
        $candidates = MarkerSubjectType::PullRequest === $type ? $this->ruleSubject->subjects($marker->when, $run->facts) : [$bound];
        $previous = array_values(array_filter($storedInbox ?? [], static fn (StoredMarker $entry): bool => $entry->key === $marker->key));
        $entries = [];
        foreach ($candidates as $facts) {
            if (!$marker->when->evaluate($facts)) {
                continue;
            }
            $entry = $this->entry($marker, $facts, null, $run->now);
            $subject = null !== $type && $entry->subject?->type === $type ? $entry->subject : null;
            // The first release of a card keeps the age of the board entry that the migration copied.
            $inherited = null === $storedInbox && [] === $entries ? $board?->since : null;
            if (null === $subject) {
                $spell = array_find($previous, static fn (StoredMarker $old): bool => MarkerSubjectType::Card === $old->subject?->type)->since ?? $inherited ?? $run->now;
                $entries[] = $entry->withSubject(MarkerSubject::card($run->card->id, $spell), $spell);
                continue;
            }
            $known = array_find($previous, static fn (StoredMarker $old): bool => $old->subject?->equals($subject) ?? false);
            $entries[] = $entry->withSubject($subject, $known->since ?? $inherited ?? $run->now);
        }

        return $entries;
    }

    private static function subjectType(TemplateMarker $marker): ?MarkerSubjectType
    {
        $types = [];
        foreach ($marker->when->leaves() as $leaf) {
            if ($leaf->condition instanceof NamesMarkerSubject) {
                $types[$leaf->condition->subjectType()->value] = $leaf->condition->subjectType();
            }
        }

        return 1 === \count($types) ? array_first($types) : null;
    }

    private static function applies(TemplateMarker $marker, ?string $slot): bool
    {
        return [] === $marker->slots || \in_array($slot, $marker->slots, true);
    }

    private function entry(TemplateMarker $marker, Facts $facts, ?StoredMarker $stored, \DateTimeImmutable $now): StoredMarker
    {
        $params = [];
        $subject = null;
        foreach ($marker->when->leaves() as $leaf) {
            if (!$leaf->condition instanceof MarkerEvidence || !$leaf->evaluate($facts)) {
                continue;
            }
            $evidence = $leaf->condition->evidence($facts, $leaf->params);
            if (null === $evidence) {
                continue;
            }
            $params = [...$params, ...$evidence->params];
            $subject ??= $evidence->subject;
        }

        return new StoredMarker($marker->key, $marker->kind, $marker->label, $marker->remedy, $params, $stored->since ?? $now, $marker->link, $subject);
    }

    /** The time of a leaf counts whether the leaf is true or false now, and on every open pull request, so a marker that turns true later is checked again. */
    private function changesAt(TemplateMarker $marker, Facts $facts): ?\DateTimeImmutable
    {
        $dueAt = null;
        foreach ($marker->when->leaves() as $leaf) {
            if ($leaf->condition instanceof TimedCondition) {
                $dueAt = self::earlier($dueAt, $leaf->condition->changesAt($facts, $leaf->params));
            }
        }

        return $dueAt;
    }

    private static function earlier(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): ?\DateTimeImmutable
    {
        return null === $a || (null !== $b && $b < $a) ? $b : $a;
    }
}
