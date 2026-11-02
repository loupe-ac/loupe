<?php

declare(strict_types=1);

namespace App\Module\OAuth\Security;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The owner manages a key. An admin may revoke any key, which stays a separate
 * attribute so the owner policy and the admin policy change on their own.
 *
 * @extends Voter<'api_key.manage'|'api_key.revoke_as_admin', ApiKey>
 */
final class ApiKeyVoter extends Voter
{
    public const string MANAGE = 'api_key.manage';
    public const string REVOKE_AS_ADMIN = 'api_key.revoke_as_admin';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::MANAGE, self::REVOKE_AS_ADMIN], true) && $subject instanceof ApiKey;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (self::REVOKE_AS_ADMIN === $attribute) {
            return \in_array('ROLE_ADMIN', $token->getRoleNames(), true);
        }

        $user = $token->getUser();

        return $user instanceof User && null !== $user->id && $user->id->equals($subject->owner->id);
    }
}
