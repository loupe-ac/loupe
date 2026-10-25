<?php

declare(strict_types=1);

namespace App\Module\Notification\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\RecordPresenceCommand;
use App\Module\Notification\Command\RecordPresenceHandler;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * A page that is in use posts here every 30 seconds. It writes the cache alone and never the session,
 * so the route takes no session lock.
 */
#[CsrfToken('submit')]
#[Route(
    '/account/presence',
    name: 'app_account_presence',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['POST'],
)]
final class RecordPresenceController extends AppController
{
    public function __construct(
        private readonly RecordPresenceHandler $recordPresence,

        #[Autowire(service: 'limiter.notification_presence')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        if (!$this->limiter->create('user:'.$user->getUserIdentifier())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many presence posts.');
        }

        ($this->recordPresence)(new RecordPresenceCommand($user));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
