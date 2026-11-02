<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Api\Problem\ApiPaths;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles the output appends of the bridge on a budget of their own, because
 * a running worker sends them far more often than it sends a state report.
 * It keys per token, as RateLimitWorkerRunReports does.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RateLimitWorkerRunOutput
{
    public const string ROUTE = 'api_project_worker_run_output_report';

    public function __construct(
        #[Autowire(service: 'limiter.agent_run_output')]
        private RateLimiterFactoryInterface $limiter,
        private CredentialRateLimitKey $key,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (self::ROUTE !== ApiPaths::canonicalRoute($request->attributes->get('_route'))) {
            return;
        }

        if (!$this->limiter->create($this->key->forRequest($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many output appends. Please slow down.');
        }
    }
}
