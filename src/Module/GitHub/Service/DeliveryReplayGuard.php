<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\GitHub\GitHubDelivery;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * GitHub signs the body of a delivery and nothing else, so only the hash of
 * the body names a copy. A copy that arrives after the window acts as a new
 * delivery, because the body carries no time that Loupe could check.
 */
final readonly class DeliveryReplayGuard
{
    private const int WINDOW_SECONDS = 2_592_000;

    public function __construct(
        #[Autowire(service: 'cache.github_delivery')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** @param string $scope `app`, or `hook.<hook id>`, so one body sent to two hooks runs twice */
    public function seen(string $scope, GitHubDelivery $delivery): bool
    {
        return $this->cache->getItem($this->key($scope, $delivery))->isHit();
    }

    public function remember(string $scope, GitHubDelivery $delivery): void
    {
        $item = $this->cache->getItem($this->key($scope, $delivery));
        $item->set(true)->expiresAfter(self::WINDOW_SECONDS);
        $this->cache->save($item);
    }

    private function key(string $scope, GitHubDelivery $delivery): string
    {
        return 'github.delivery.'.hash('sha256', $scope.'|'.$delivery->bodyHash);
    }
}
