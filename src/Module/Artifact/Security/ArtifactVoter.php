<?php

declare(strict_types=1);

namespace App\Module\Artifact\Security;

use App\Module\Artifact\Entity\Artifact;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'artifact.view'|'artifact.manage', Artifact>
 */
final class ArtifactVoter extends Voter
{
    public const string VIEW = 'artifact.view';

    /** Changing the artifact itself, such as archiving it. */
    public const string MANAGE = 'artifact.manage';

    private const array SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::MANAGE,
    ];

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && $subject instanceof Artifact;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Kept apart on purpose: widening reading to collaborators must not
        // hand them the writes as a side effect.
        return match ($attribute) {
            self::VIEW => $subject->project->owner === $user,
            self::MANAGE => $subject->project->owner === $user,
        };
    }
}
