<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Service\CallerRun;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Binds the sessionId argument of a tool to the session that makes the call.
 * The session of a run credential wins over the header. A direct MCP client sends neither,
 * so the server cannot bind it and trusts the argument.
 */
final readonly class CallerSessionGuard
{
    public const string NOT_CALLER = 'inbox.session.error.not_caller';

    public function __construct(
        private RequestStack $requests,
        private CallerRun $callerRun,
    ) {
    }

    /** @throws DomainErrors when the call carries a credential or a session header that names another session */
    public function requireOwnSession(Uuid $sessionId): void
    {
        $credentialSession = $this->callerRun->workerSession();
        if (null !== $credentialSession) {
            if (!$credentialSession->equals($sessionId)) {
                throw new DomainErrors(['sessionId' => self::NOT_CALLER]);
            }

            return;
        }

        $header = $this->requests->getCurrentRequest()?->headers->get(CallerRun::SESSION_HEADER);
        if (null === $header) {
            return;
        }
        if (!Uuid::isValid($header) || !Uuid::fromString($header)->equals($sessionId)) {
            throw new DomainErrors(['sessionId' => self::NOT_CALLER]);
        }
    }
}
