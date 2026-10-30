<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\LoginLivenessInterface;
use Symfony\Component\Uid\Uuid;

/** Answers the login questions from fields a test sets, and records what it was asked. */
final class StubLoginLiveness implements LoginLivenessInterface
{
    /** @var list<string> the access token ids looked up */
    public array $lookups = [];

    /** @var list<list<string>> the login ids of each expired() call */
    public array $asked = [];

    /** @param list<string> $expired RFC 4122 ids of the logins that count as expired */
    public function __construct(
        public ?Uuid $login = null,
        public array $expired = [],
    ) {
    }

    #[\Override]
    public function loginOfAccessToken(string $accessTokenId): ?Uuid
    {
        $this->lookups[] = $accessTokenId;

        return $this->login;
    }

    #[\Override]
    public function expired(array $loginIds): array
    {
        $this->asked[] = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $loginIds);

        return array_values(array_filter($loginIds, fn (Uuid $id): bool => \in_array($id->toRfc4122(), $this->expired, true)));
    }
}
