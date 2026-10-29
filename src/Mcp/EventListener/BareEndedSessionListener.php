<?php

declare(strict_types=1);

namespace App\Mcp\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Sends a bare 404 for an MCP session that has ended. The MCP specification
 * reads that status as "open a new session", but a client built on the Go MCP
 * library reads a JSON error body first and reports a failed call instead.
 */
#[AsEventListener]
final readonly class BareEndedSessionListener
{
    private const string MCP_ROUTE = '_mcp_endpoint';

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if (Response::HTTP_NOT_FOUND !== $response->getStatusCode()) {
            return;
        }

        $request = $event->getRequest();
        if (self::MCP_ROUTE !== $request->attributes->get('_route') || !$request->headers->has('Mcp-Session-Id')) {
            return;
        }

        $response->setContent('');
        $response->headers->set('Content-Length', '0');
        $response->headers->remove('Content-Type');
    }
}
