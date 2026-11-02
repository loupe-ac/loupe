<?php

declare(strict_types=1);

namespace App\Api\EventListener;

use App\Api\Problem\ApiPaths;
use App\Api\Problem\ApiProblemException;
use App\Api\RateLimit\ApiBudget;
use App\Security\AuthenticatedCredential;
use App\Security\CredentialRateLimitKey;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Spends one request of the budget of the credential on a public route, and refuses
 * the request with a Problem 429 when the window is spent.
 *
 * A route with no sign-in, such as the spec, spends from the budget of the client
 * address and sends no budget headers. The bridge and widget routes keep their own
 * limits. The priority runs after the firewall, so the credential is resolved.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class ApplyApiBudgetOnRequest
{
    public const string ATTRIBUTE = '_api_budget';

    public function __construct(
        private ApiBudget $budget,
        private CredentialRateLimitKey $key,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !ApiPaths::isPublic($request->getPathInfo()) || $request->isMethod('OPTIONS')) {
            return;
        }

        $write = !$request->isMethodSafe();
        $limit = $this->budget->factory($write)->create($this->key->forRequest($request))->consume();
        $hasCredential = null !== AuthenticatedCredential::of($this->tokenStorage->getToken());

        $resetIn = max(0, $limit->getRetryAfter()->getTimestamp() - time());
        $headers = $hasCredential ? [
            'RateLimit-Limit' => (string) $limit->getLimit(),
            'RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
            'RateLimit-Reset' => (string) $resetIn,
        ] : [];

        if (!$limit->isAccepted()) {
            throw ApiProblemException::rateLimited(max(1, $resetIn), $headers);
        }

        $request->attributes->set(self::ATTRIBUTE, $headers);
    }
}
