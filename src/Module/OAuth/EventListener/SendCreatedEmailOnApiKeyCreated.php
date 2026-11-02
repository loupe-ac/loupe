<?php

declare(strict_types=1);

namespace App\Module\OAuth\EventListener;

use App\Module\OAuth\Event\ApiKeyCreated;
use App\Module\OAuth\Service\ApiKeyEmailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class SendCreatedEmailOnApiKeyCreated
{
    public function __construct(
        private ApiKeyEmailSender $emails,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApiKeyCreated $event): void
    {
        try {
            $this->emails->sendCreated($event->key);
        } catch (\Throwable $e) {
            // The key is already active, and this request is the only one that can show it.
            $this->logger->warning('oauth.api_key_created_email_failed', ['keyId' => (string) $event->key->id, 'exception' => $e]);
        }
    }
}
