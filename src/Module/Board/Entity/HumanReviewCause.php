<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** Why the human review switch of a card changed, as the card's history records it. */
enum HumanReviewCause: string
{
    case Person = 'person';
    case Agent = 'agent';
    case Rule = 'rule';
    case Rollout = 'rollout';
}
