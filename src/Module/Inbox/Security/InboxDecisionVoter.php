<?php

declare(strict_types=1);

namespace App\Module\Inbox\Security;

use App\Module\Inbox\Entity\InboxDecision;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may change a decision: the owner of its project, who is also the person who answers the items of that project.
 *
 * @extends Voter<'inbox_decision.change', InboxDecision>
 */
final class InboxDecisionVoter extends Voter
{
    /** Changing a decision, and creating the follow-up card of a change. */
    public const string CHANGE = 'inbox_decision.change';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::CHANGE === $attribute && $subject instanceof InboxDecision;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $subject->project->owner === $token->getUser();
    }
}
