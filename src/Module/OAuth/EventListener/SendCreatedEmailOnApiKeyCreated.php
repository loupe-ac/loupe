<?php

declare(strict_types=1);

namespace App\Module\OAuth\EventListener;

use App\Module\OAuth\Event\ApiKeyCreated;
use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Module\OAuth\Service\ApiKeyEmailSender;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class SendCreatedEmailOnApiKeyCreated
{
    public function __construct(
        private ApiKeyEmailSender $emails,
        private ApiKeyRepository $apiKeys,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApiKeyCreated $event): void
    {
        $key = $event->key;

        try {
            $this->emails->sendCreated($key);
            $this->apiKeys->markCreatedNoticeSent($key->id ?? throw new \LogicException('A created key has an id.'), $this->clock->now());
        } catch (\Throwable $e) {
            // The key is already active, and this request is the only one that can show it. The empty stamp leaves the mail to the sweep.
            $this->logger->warning('oauth.api_key_created_email_failed', ['keyId' => (string) $key->id, 'exception' => $e]);
        }
    }
}
