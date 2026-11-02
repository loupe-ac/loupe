<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Api\Problem\ApiPaths;
use App\Api\Problem\ApiProblem;
use App\Module\Account\Entity\User;
use App\Module\Billing\Service\PaywallExemptions;
use App\Module\Billing\Service\PaywallGate;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

// Priority 4: after the security firewall (8) and RedirectUnverifiedUserListener (5),
// so the token is populated and unverified users were already diverted.
#[AsEventListener(priority: 4)]
final readonly class RequireSubscriptionListener
{
    /**
     * Machine clients authenticate against the stateless token firewalls with an
     * API token. They are gated exactly like the UI, but an HTML 302 to the
     * subscribe page would corrupt their response rather than explain anything,
     * so they get a 402 with a machine-readable body instead.
     */
    private const array MACHINE_PATH_PREFIXES = ['/api/', '/mcp'];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private PaywallGate $gate,
        private PaywallExemptions $exemptions,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User || !$user->isVerified()) {
            return;
        }

        $request = $event->getRequest();
        $isMachineRequest = array_any(self::MACHINE_PATH_PREFIXES, fn ($prefix) => str_starts_with($request->getPathInfo(), (string) $prefix));

        if (!$isMachineRequest) {
            $route = $request->attributes->get('_route');
            if (!is_string($route) || $this->exemptions->exempts($route)) {
                return;
            }
        }

        if ($this->gate->allows($user)) {
            return;
        }

        if (!$isMachineRequest) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_billing_subscribe')));

            return;
        }

        $problem = new ApiProblem(
            Response::HTTP_PAYMENT_REQUIRED,
            'subscription_required',
            'Subscription required',
            'This account needs an active subscription.',
            extras: ['subscribeUrl' => $this->urlGenerator->generate('app_billing_subscribe', referenceType: UrlGeneratorInterface::ABSOLUTE_URL)],
        );
        $event->setResponse(ApiPaths::usesProblemJson($request->getPathInfo())
            ? new JsonResponse($problem->toProblemBody(), $problem->status, ['Content-Type' => 'application/problem+json'])
            : new JsonResponse($problem->toLegacyBody(), $problem->status));
    }
}
