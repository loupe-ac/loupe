<?php

declare(strict_types=1);

namespace App\Module\Artifact\EventListener;

use App\Module\Artifact\Controller\ShowArtifactContentController;
use App\Module\Artifact\Service\ArtifactContentHost;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The content domain points at the same application, and the router matches
 * any route on any host. Only the content route may answer there.
 */
#[AsEventListener(priority: 16)]
final readonly class RefuseAppRoutesOnContentHost
{
    public function __construct(
        private ArtifactContentHost $contentHost,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->contentHost->ownsHost($request->getHost())) {
            return;
        }

        if (ShowArtifactContentController::ROUTE_HOST !== $request->attributes->get('_route')) {
            throw new NotFoundHttpException('Only stored files are served on the content domain.');
        }
    }
}
