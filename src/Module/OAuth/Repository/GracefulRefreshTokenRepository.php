<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use App\Module\OAuth\Install\OAuthInstallFlags;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Accepts a replaced refresh token again until its successor is used.
 *
 * The server revokes the old refresh token and issues the new pair before the
 * client has stored anything, so a lost answer leaves the client holding a
 * token the server refuses. Each rotation records its successor. While the
 * successor stays unused, and the first rotation is younger than the limit,
 * the old token redeems again, and the unused successor pair is revoked.
 */
#[AsDecorator('league.oauth2_server.repository.refresh_token')]
#[WithMonologChannel('security')]
final class GracefulRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    /** The token that this request revoked, until its successor is persisted. */
    private ?string $rotating = null;

    public function __construct(
        #[AutowireDecorated]
        private readonly RefreshTokenRepositoryInterface $inner,
        private readonly RefreshTokenRotationRepository $refreshTokenRotations,
        private readonly RefreshTokenLoginRepository $refreshTokenLogins,
        private readonly FeatureFlagService $featureFlags,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,

        #[Autowire(param: 'app.oauth.refresh_grace_seconds')]
        private readonly int $seconds = 604800,
    ) {
    }

    #[\Override]
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return $this->inner->getNewRefreshToken();
    }

    #[\Override]
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $this->inner->persistNewRefreshToken($refreshTokenEntity);
        $this->refreshTokenLogins->tag($refreshTokenEntity->getIdentifier(), $this->rotating);

        if (null === $this->rotating) {
            return;
        }

        $this->refreshTokenRotations->record($this->rotating, $refreshTokenEntity->getIdentifier(), $this->clock->now());
        $this->rotating = null;
    }

    #[\Override]
    public function revokeRefreshToken(string $tokenId): void
    {
        $this->rotating = $tokenId;
        $this->inner->revokeRefreshToken($tokenId);
    }

    /**
     * Revoking the unused successor is a side effect, and this question is the
     * only hook league gives before it accepts the token. The row lock comes
     * first, so the answer reflects whatever a concurrent request committed.
     */
    #[\Override]
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $this->refreshTokenRotations->lock($tokenId);

        if (!$this->inner->isRefreshTokenRevoked($tokenId)) {
            return false;
        }

        $reason = $this->refusalReason($tokenId);
        if (null === $reason) {
            return false;
        }

        $this->logger->info('oauth.refresh_token_reuse_refused', ['reason' => $reason]);

        return true;
    }

    private function refusalReason(string $tokenId): ?string
    {
        if ($this->seconds <= 0 || !$this->featureFlags->isEnabled(OAuthInstallFlags::FLAG_REFRESH_REUSE, true)) {
            return 'disabled';
        }

        $rotation = $this->refreshTokenRotations->find($tokenId);
        if (null === $rotation) {
            return 'no_rotation';
        }

        $age = $this->clock->now()->getTimestamp() - $rotation['rotatedAt']->getTimestamp();
        if ($age > $this->seconds) {
            return 'limit_passed';
        }

        $this->refreshTokenRotations->lock($rotation['successor']);
        if ($this->refreshTokenRotations->isRevoked($rotation['successor'])) {
            return 'successor_used';
        }

        $this->refreshTokenRotations->revokePair($rotation['successor']);
        $this->logger->info('oauth.refresh_token_reuse_accepted', ['ageSeconds' => $age]);

        return null;
    }
}
