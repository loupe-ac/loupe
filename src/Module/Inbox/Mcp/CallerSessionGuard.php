<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Mcp\AgentRunCause;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Binds the sessionId argument of a tool to the session that makes the call.
 * A direct MCP client sends no session header, so the server cannot bind it and trusts the argument.
 */
final readonly class CallerSessionGuard
{
    public const string NOT_CALLER = 'inbox.session.error.not_caller';

    public function __construct(
        private RequestStack $requests,
    ) {
    }

    /** @throws DomainErrors when the call carries a session header that names another session */
    public function requireOwnSession(Uuid $sessionId): void
    {
        $header = $this->requests->getCurrentRequest()?->headers->get(AgentRunCause::SESSION_HEADER);
        if (null === $header) {
            return;
        }
        if (!Uuid::isValid($header) || !Uuid::fromString($header)->equals($sessionId)) {
            throw new DomainErrors(['sessionId' => self::NOT_CALLER]);
        }
    }
}
