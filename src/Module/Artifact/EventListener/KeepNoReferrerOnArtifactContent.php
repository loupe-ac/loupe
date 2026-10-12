<?php

declare(strict_types=1);

namespace App\Module\Artifact\EventListener;

use App\Module\Artifact\Controller\ShowArtifactContentController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Runs after the nelmio referrer listener, which sets its policy with no check
 * for one already there. Without this the signed link would leave in a Referer.
 */
#[AsEventListener(priority: -100)]
final readonly class KeepNoReferrerOnArtifactContent
{
    public function __invoke(ResponseEvent $event): void
    {
        if (\in_array($event->getRequest()->attributes->get('_route'), [ShowArtifactContentController::ROUTE_SEALED, ShowArtifactContentController::ROUTE_HOST], true)) {
            $event->getResponse()->headers->set('Referrer-Policy', 'no-referrer');
        }
    }
}
