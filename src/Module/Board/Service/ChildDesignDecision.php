<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;

/** Checks the choice a caller states for a card it files under a parent, before the card handlers write anything. */
final readonly class ChildDesignDecision
{
    public function __construct(
        private CardParentResolver $parents,
        private ChildDesignChoices $choices,
    ) {
    }

    /**
     * Null when the call carries no choice to run: the card write goes ahead as it does with no choice.
     *
     * @param ?string      $parentCardId the parent the call names, with '' for clearing the parent and null for keeping it
     * @param ?Card        $current      the card that an update changes, null for a create
     * @param list<string> $documentIds  the documents the call links
     *
     * @throws ChildDesignRefused when the choice is wrong, or missing where the owner would get the question
     */
    public function resolve(Project $project, ?string $parentCardId, ?Card $current, ?string $childDesign, array $documentIds, bool $statusGiven): ?string
    {
        if (null !== $childDesign && !\in_array($childDesign, ChildDesignChoices::CHOICES, true)) {
            throw new ChildDesignRefused('childDesign: Pass "inherit" or "own".');
        }
        $parentSet = null !== $parentCardId && '' !== $parentCardId;
        $parent = $parentSet ? $this->parent($project, $parentCardId) : (null === $parentCardId ? $current?->parent : null);
        if ($parentSet && null === $parent) {
            // The card handler reports the parent that does not resolve.
            return null;
        }
        if (null === $parent) {
            return null === $childDesign ? null : throw new ChildDesignRefused('childDesign: The card has no parent card. Pass parentCardId, or leave childDesign out.');
        }

        $targets = $this->choices->forChild($parent, $documentIds);
        if (null === $childDesign) {
            $parentChanges = $parentSet && true !== $parent->id?->equals($current?->parent?->id);
            if ($parentChanges && true === $targets?->choiceRequired) {
                throw new ChildDesignRefused('childDesign: This card joins a parent whose tech design is approved. Pass "inherit" if that design covers the card, or "own" if the card needs a design of its own.');
            }

            return null;
        }
        if (null === $targets || !\in_array($childDesign, $targets->declared, true)) {
            throw new ChildDesignRefused('childDesign: The workflow of this project declares no such choice. Leave childDesign out.');
        }
        if (ChildDesignChoices::OWN === $childDesign && $statusGiven) {
            throw new ChildDesignRefused('childDesign: "own" sets the column of the card, so pass no status with it.');
        }
        if (ChildDesignChoices::INHERIT === $childDesign && !$targets->inheritAvailable) {
            throw new ChildDesignRefused('childDesign: The parent card has no tech design to inherit. Pass "own" to give this card a design of its own.');
        }

        return $childDesign;
    }

    private function parent(Project $project, string $parentCardId): ?Card
    {
        try {
            return $this->parents->resolve($project, $parentCardId);
        } catch (DomainErrors) {
            return null;
        }
    }
}
