<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\Account\Admin\AdminUserPanel;
use App\Module\Account\Admin\AdminUserPanelInterface;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\ApiKeyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * OAuth's API keys section on the admin user detail page. It always renders,
 * because "this account has no key" is the answer an admin often wants.
 *
 * The context carries scalars and dates only, so Account never learns an OAuth
 * type. The status is one of `active`, `expired` and `revoked`.
 */
#[AsTaggedItem(priority: 4)]
final readonly class ApiKeysAdminUserPanel implements AdminUserPanelInterface
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function panelFor(User $user): AdminUserPanel
    {
        $now = $this->clock->now();

        $keys = [];
        foreach ($this->apiKeys->findByOwner($user) as $key) {
            $keys[] = [
                'id' => (string) $key->id,
                'name' => $key->name,
                'displayStart' => $key->displayStart,
                'status' => match (true) {
                    $key->isRevoked() => 'revoked',
                    $key->isExpiredAt($now) => 'expired',
                    default => 'active',
                },
                'expiresAt' => $key->expiresAt,
                'lastUsedAt' => $key->lastUsedAt,
            ];
        }

        return new AdminUserPanel('@OAuth/admin/api_keys_panel.html.twig', ['keys' => $keys]);
    }
}
