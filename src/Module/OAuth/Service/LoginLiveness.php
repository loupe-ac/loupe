<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\OAuth\Repository\RefreshTokenLoginRepository;
use App\Security\LoginLivenessInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(LoginLivenessInterface::class)]
final readonly class LoginLiveness implements LoginLivenessInterface
{
    public function __construct(
        private RefreshTokenLoginRepository $refreshTokenLogins,
    ) {
    }

    #[\Override]
    public function loginOfAccessToken(string $accessTokenId): ?Uuid
    {
        return $this->refreshTokenLogins->loginOfAccessToken($accessTokenId);
    }

    #[\Override]
    public function expired(array $loginIds): array
    {
        $live = $this->refreshTokenLogins->live(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $loginIds));

        return array_values(array_filter($loginIds, static fn (Uuid $id): bool => !\in_array($id->toRfc4122(), $live, true)));
    }
}
