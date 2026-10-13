<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Workflow\Contract\CardMarkerStore;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\MarkerEvidence;
use App\Module\Workflow\Contract\MarkerKind;
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
        $dueAt = null;
        if (SlotKeys::TERMINAL !== $run->facts->slot) {
            $stored = [];
            foreach ($this->store->read($run->card->id) as $marker) {
                $stored[$marker->key] = $marker;
            }
            [$found, $dueAt] = $this->find($run, $stored);
            $markers = $found;
        }

        $this->store->write($run->card->projectId, $run->card->id, $markers, $dueAt);
    }

    /** Stores an empty set for a card that has no template to read markers from. */
    public function clear(CardSnapshot $card): void
    {
        $this->store->write($card->projectId, $card->id, [], null);
    }

    /**
     * @param array<string, StoredMarker> $stored
     *
     * @return array{list<StoredMarker>, ?\DateTimeImmutable} the markers in display order, and the earliest time a timed condition changes
     */
    private function find(Evaluation $run, array $stored): array
    {
        $matched = [];
        $fallback = null;
        $stateMatched = false;
        $dueAt = null;
        foreach ($run->template->markers as $marker) {
            if (!self::applies($marker, $run->facts->slot) || null !== $marker->when->unreadable($run->facts)) {
                continue;
            }
            $bound = $this->ruleSubject->bindWhen($marker->when, $run->facts);
            $dueAt = self::earlier($dueAt, $this->changesAt($marker, $bound->facts));
            if (!$bound->truth) {
                continue;
            }
            $entry = $this->entry($marker, $bound->facts, $stored[$marker->key] ?? null, $run->now);
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

        return [$matched, $dueAt];
    }

    private static function applies(TemplateMarker $marker, ?string $slot): bool
    {
        return [] === $marker->slots || \in_array($slot, $marker->slots, true);
    }

    private function entry(TemplateMarker $marker, Facts $facts, ?StoredMarker $stored, \DateTimeImmutable $now): StoredMarker
    {
        $params = [];
        $since = null;
        foreach ($marker->when->leaves() as $leaf) {
            if (!$leaf->condition instanceof MarkerEvidence || !$leaf->evaluate($facts)) {
                continue;
            }
            $evidence = $leaf->condition->evidence($facts, $leaf->params);
            if (null === $evidence) {
                continue;
            }
            $params = [...$params, ...$evidence->params];
            $since ??= $evidence->since;
        }

        return new StoredMarker($marker->key, $marker->kind, $marker->label, $marker->remedy, $params, $since ?? $stored->since ?? $now);
    }

    /** The time of a leaf counts whether the leaf is true or false now, so a marker that turns true later is checked again. */
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
