<?php

declare(strict_types=1);

namespace App\Api\EventListener;

use App\Api\Idempotency\Entity\IdempotencyRecord;
use App\Api\Idempotency\IdempotencyState;
use App\Api\Idempotency\Repository\IdempotencyRecordRepository;
use App\Api\Idempotency\Service\IdempotencyRequestFingerprint;
use App\Api\Problem\ApiPaths;
use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Module\Account\Entity\User;
use App\Security\AuthenticatedCredential;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Claims the `Idempotency-Key` of a public POST before the handler runs.
 *
 * The claim commits in a statement of its own, so a retry always sees it. A retry
 * on a finished claim gets the stored answer, and a retry on a running one gets a
 * 409. Nothing frees a running claim early: the server cannot tell a slow request
 * from a dead one, and a dead one may have committed its create. The budget
 * listener runs first, so its 429 claims nothing. A route limit runs after this
 * listener, and StoreIdempotentAnswerOnResponse frees the claim on its 429.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 3)]
final readonly class ClaimIdempotencyKeyOnRequest
{
    public const string HEADER = 'Idempotency-Key';

    public const string ATTRIBUTE = '_api_idempotency_record';

    public const string LIFETIME = '+24 hours';

    public function __construct(
        private IdempotencyRecordRepository $idempotencyRecords,
        private IdempotencyRequestFingerprint $fingerprint,
        private TokenStorageInterface $tokenStorage,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $key = $request->headers->get(self::HEADER);
        if (null === $key || !$event->isMainRequest() || !$request->isMethod('POST') || !ApiPaths::isPublic($request->getPathInfo())) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $credential = AuthenticatedCredential::of($token);
        $user = $token?->getUser();
        if (null === $credential || !$user instanceof User || null === $user->id) {
            return;
        }

        if ('' === trim($key) || \strlen($key) > IdempotencyRecord::MAX_KEY_LENGTH) {
            throw ApiProblemException::validationFailed([['field' => self::HEADER, 'code' => 'idempotency_key_invalid', 'message' => 'The key must have 1 to '.IdempotencyRecord::MAX_KEY_LENGTH.' characters.']]);
        }

        $hash = $this->fingerprint->of($request);
        $now = $this->clock->now();
        $id = $this->idempotencyRecords->claim($credential->id, $key, $user->id, $hash, $now, $now->modify(self::LIFETIME));
        if (null !== $id) {
            $request->attributes->set(self::ATTRIBUTE, $id);

            return;
        }

        $held = $this->idempotencyRecords->findHeld($credential->id, $key);
        if (null === $held) {
            throw new ApiProblemException(new ApiProblem(409, 'idempotency_in_progress', 'Request in progress', 'A request with this key is still running, or ended before it answered. Read the list to see whether it created anything.'));
        }
        if ($held['requestHash'] !== $hash) {
            throw new ApiProblemException(new ApiProblem(409, 'idempotency_key_reused', 'Idempotency key reused', 'This key was used for a different request. Send a new key.'));
        }
        if (IdempotencyState::Running === $held['state'] || null === $held['status']) {
            throw new ApiProblemException(new ApiProblem(409, 'idempotency_in_progress', 'Request in progress', 'A request with this key is still running, or ended before it answered. Read the list to see whether it created anything.'));
        }

        $event->setResponse(new Response($held['body'] ?? '', $held['status'], $held['headers']));
    }
}
