<?php

declare(strict_types=1);

namespace App\Module\OAuth\Security;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<'api_key.manage', ApiKey> */
final class ApiKeyVoter extends Voter
{
    public const string MANAGE = 'api_key.manage';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof ApiKey;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && null !== $user->id && $user->id->equals($subject->owner->id);
    }
}
