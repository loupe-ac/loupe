<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Security;

use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Project management is the owner's today, so a checker stands in for a person who may view but not manage. */
trait DeniesProjectManage
{
    /** Call it before the first request of the test. */
    private function denyManage(): void
    {
        $container = static::getContainer();
        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $decisions = $container->get('security.access.decision_manager');
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $decisions);
        $real = new AuthorizationChecker($tokens, $decisions);

        $container->set('security.authorization_checker', new readonly class($real) implements AuthorizationCheckerInterface {
            public function __construct(
                private AuthorizationCheckerInterface $real,
            ) {
            }

            #[\Override]
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return ProjectVoter::MANAGE !== $attribute && $this->real->isGranted($attribute, $subject, $accessDecision);
            }
        });
    }
}
