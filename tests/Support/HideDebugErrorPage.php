<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The debug error page quotes the source of each stack frame, the test method
 * included. A test that asserts a refused page does not contain a value then
 * fails on its own source. This listener makes every request, the error
 * sub-request included, render the error page a visitor sees.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 2048)]
final class HideDebugErrorPage
{
    public function __invoke(RequestEvent $event): void
    {
        $event->getRequest()->attributes->set('showException', false);
    }
}
