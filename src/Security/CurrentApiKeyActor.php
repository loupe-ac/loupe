<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Module\OAuth\Security\ApiKeyAuthenticator;
use App\Module\Workflow\Contract\Actor;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/** Tells whether the current request came in on an API key, and which one. */
final readonly class CurrentApiKeyActor
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ApiKeyRepository $apiKeys,
    ) {
    }

    /** Null when the request holds no API key, or the key row is gone. */
    public function current(): ?ApiKeyActor
    {
        $keyId = $this->keyId();
        if (null === $keyId) {
            return null;
        }
        $key = $this->apiKeys->find($keyId);

        return null === $key ? null : new ApiKeyActor($keyId, $key->name);
    }

    /** The kind a change made on this request records: ApiKey on a key, else the one the caller would use. */
    public function actorKind(Actor $fallback): Actor
    {
        return null === $this->credentialId() ? $fallback : Actor::ApiKey;
    }

    private function credentialId(): ?string
    {
        $id = AuthenticatedCredential::of($this->tokenStorage->getToken())?->id;

        return null !== $id && str_starts_with($id, ApiKeyAuthenticator::CREDENTIAL_PREFIX) ? $id : null;
    }

    private function keyId(): ?Uuid
    {
        $id = $this->credentialId();
        if (null === $id) {
            return null;
        }
        $raw = substr($id, \strlen(ApiKeyAuthenticator::CREDENTIAL_PREFIX));

        return Uuid::isValid($raw) ? Uuid::fromString($raw) : null;
    }
}
