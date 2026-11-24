<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardChanged;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/** Writes the decision entries of a card, and tells the open pages after the commit. */
final readonly class InboxDecisionLog
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
        private InboxChangedPublisher $inboxChanged,
    ) {
    }

    /**
     * Persists one entry for each card, and leaves the flush to the caller.
     *
     * @param list<Card>   $cards
     * @param list<string> $options every option of the ask, in order
     *
     * @return list<InboxDecision>
     */
    public function record(Project $project, array $cards, string $label, int $chosen, array $options, InboxDecisionMadeBy $madeBy, ?InboxItem $item = null): array
    {
        $choice = $options[$chosen] ?? throw new \LogicException('The chosen option is one of the options.');
        $others = array_values(array_filter($options, static fn (int $index): bool => $index !== $chosen, \ARRAY_FILTER_USE_KEY));

        $entries = [];
        foreach ($cards as $card) {
            $entry = new InboxDecision($project, $card, $label, $choice, $others, $madeBy);
            $entry->item = $item;
            $this->em->persist($entry);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Call it after the commit. The card page redraws its Decisions tab, and the inbox pages read their rows again.
     *
     * @param list<Uuid> $cardIds
     * @param bool       $signalInbox false when the caller signals the inbox pages itself
     */
    public function refresh(Project $project, array $cardIds, bool $signalInbox = true): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        foreach ($cardIds as $cardId) {
            $this->events->dispatch(new CardChanged($projectId, $cardId, CardChanged::UPDATED, false));
        }
        if ($signalInbox) {
            $this->inboxChanged->pageChanged($project);
        }
    }
}
