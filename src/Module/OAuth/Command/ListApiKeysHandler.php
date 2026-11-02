<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Repository\ApiKeyRepository;

final readonly class ListApiKeysHandler
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
    ) {
    }

    public function __invoke(ListApiKeysCommand $command): ListApiKeysView
    {
        return new ListApiKeysView($this->apiKeys->findByOwner($command->owner));
    }
}
