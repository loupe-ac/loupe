<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Service\CallerRun;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Counts an MCP request that names its session, by its run credential or its header, as a sign of life of the open
 * interactive runs of that session. The stamp is written after the response,
 * so it never slows the call. It reaches only the project the MCP credential
 * is bound to, so a forged header changes no run of another project.
 */
final readonly class StampInteractiveActivity
{
    private const string ATTRIBUTE = '_interactive_activity';

    public function __construct(
        private AuthenticatedProjectResolver $projects,
        private CallerRun $callerRun,
        private InteractiveRuns $interactiveRuns,
        private ClockInterface $clock,
    ) {
    }

    /** Resolves the project while the request is still on the stack, because the resolver reads its header. */
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function resolve(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isMcpEndpoint($request->getPathInfo())) {
            return;
        }

        $session = $this->callerRun->workerSession();
        if (null === $session) {
            $header = $request->headers->get(CallerRun::SESSION_HEADER);
            if (null === $header || !Uuid::isValid($header)) {
                return;
            }
            $session = Uuid::fromString($header);
        }

        $project = $this->projects->resolveMcpProject();
        if (null !== $project) {
            $request->attributes->set(self::ATTRIBUTE, [$project, $session]);
        }
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function stamp(TerminateEvent $event): void
    {
        $resolved = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (!\is_array($resolved)) {
            return;
        }

        [$project, $sessionId] = $resolved;
        $this->interactiveRuns->touch($project, $sessionId, $this->clock->now());
    }

    private static function isMcpEndpoint(string $path): bool
    {
        return '/mcp' === $path || str_starts_with($path, '/mcp/');
    }
}
