<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Repository\ApiKeyRepository;
use Psr\Clock\ClockInterface;

final readonly class ListApiKeysHandler
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ListApiKeysCommand $command): ListApiKeysView
    {
        $keys = array_values(array_filter(
            $this->apiKeys->findByOwner($command->owner),
            static fn (ApiKey $key): bool => !$key->isRevoked(),
        ));

        return new ListApiKeysView($keys, $this->clock->now());
    }
}
