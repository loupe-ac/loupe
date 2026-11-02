<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\HumanReviewCause;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Workflow\Contract\Actor;

/** Sets the human review switch of a card, and records the change in its history. */
final readonly class HumanReviewSwitch
{
    public function __construct(
        private CardEventRepository $cardEvents,
        private CardEventActor $eventActor,
    ) {
    }

    /**
     * Call it after the card's artifact links and parent are final, and before the flush that stores them.
     * A value the card already holds changes nothing and writes no row.
     * With no cause, a person's change reads as `person`, an agent's as `agent`, and any other actor's as `rule`.
     */
    public function set(Card $card, bool $value, Actor $actor, ?HumanReviewCause $cause = null, ?string $ruleId = null): bool
    {
        if ($value === $card->requiresHumanReview) {
            return false;
        }

        $artifactIds = $card->reachingArtifactIds();
        $card->requiresHumanReview = $value;
        $card->humanReviewSeenArtifacts = $artifactIds;
        $this->cardEvents->record($card, CardEventKind::HumanReviewChanged, $actor, $this->eventActor->userFor($actor), [
            'value' => $value,
            'cause' => ($cause ?? match ($actor) {
                Actor::Human => HumanReviewCause::Person,
                Actor::Agent, Actor::ApiKey => HumanReviewCause::Agent,
                Actor::System, Actor::Reviewer => HumanReviewCause::Rule,
            })->value,
            'ruleId' => '' === $ruleId ? null : $ruleId,
            'artifactIds' => $artifactIds,
        ], null, $this->eventActor->apiKeyFor($actor));

        return true;
    }
}
