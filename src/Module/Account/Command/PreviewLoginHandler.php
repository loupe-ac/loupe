<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\CurrentProjectProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * SECURITY: this grants a session for a seeded account. #[When('dev')] is what
 * keeps the service out of production; the signature that authorises the
 * request belongs to the route and is checked before this runs.
 */
#[When('dev')]
final readonly class PreviewLoginHandler
{
    /** @param UserProviderInterface<User> $userProvider */
    public function __construct(
        private UserProviderInterface $userProvider,
        private Security $security,
        private ProjectRepository $projects,
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(PreviewLoginCommand $command): ?User
    {
        try {
            $user = $this->userProvider->loadUserByIdentifier($command->email);
        } catch (UserNotFoundException) {
            return null;
        }

        $this->security->login($user, 'form_login');

        // A link carries no session, so the account page would show no remembered project. Seed it.
        $remembered = $this->projects->findOldestByOwner($user);
        $session = $this->requestStack->getCurrentRequest()?->getSession();
        if (null !== $remembered && null !== $session) {
            $session->set(CurrentProjectProvider::LAST_VISITED_SESSION_KEY, (string) $remembered->id);
        }

        return $user;
    }
}
