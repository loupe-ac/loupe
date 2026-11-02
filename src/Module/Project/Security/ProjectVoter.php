<?php

declare(strict_types=1);

namespace App\Module\Project\Security;

use App\Module\Project\Entity\Project;
use App\Security\AuthenticatedCredential;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The owner views and manages a project. A credential bound to one project reaches that project alone.
 *
 * @extends Voter<'project.view'|'project.manage', Project>
 */
final class ProjectVoter extends Voter
{
    public const string VIEW = 'project.view';
    public const string MANAGE = 'project.manage';

    private const array SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::MANAGE,
    ];

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && $subject instanceof Project;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($subject->owner !== $token->getUser()) {
            return false;
        }

        $bound = AuthenticatedCredential::of($token)?->projectId;

        return null === $bound || $bound->equals($subject->id);
    }
}
