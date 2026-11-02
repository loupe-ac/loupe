<?php

declare(strict_types=1);

namespace App\Module\OAuth\EventListener;

use App\Module\OAuth\Event\ApiKeyCreated;
use App\Module\OAuth\Service\ApiKeyEmailSender;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class SendCreatedEmailOnApiKeyCreated
{
    public function __construct(
        private ApiKeyEmailSender $emails,
    ) {
    }

    public function __invoke(ApiKeyCreated $event): void
    {
        $this->emails->sendCreated($event->key);
    }
}
