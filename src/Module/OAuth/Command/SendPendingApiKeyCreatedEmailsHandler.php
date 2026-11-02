<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Module\OAuth\Service\ApiKeyEmailSender;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Queues the created mail of each key whose mail never reached the queue. It
 * stamps a key only after the queue accepts the mail, so a failure stays
 * pending, and a crash between the two steps sends a second mail rather than
 * none. A revoked key still gets the mail, because the owner must learn of a key
 * that they did not make.
 */
final readonly class SendPendingApiKeyCreatedEmailsHandler
{
    /** Leaves a key alone while the request that created it can still queue the mail. */
    private const string GRACE = '-5 minutes';

    public function __construct(
        private ApiKeyRepository $apiKeys,
        private ApiKeyEmailSender $emails,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @return int how many mails were queued */
    public function __invoke(SendPendingApiKeyCreatedEmailsCommand $command): int
    {
        $now = $this->clock->now();
        $sent = 0;

        foreach ($this->apiKeys->findCreatedNoticePending($now->modify(self::GRACE)) as $key) {
            try {
                $this->emails->sendCreated($key);
                $this->apiKeys->markCreatedNoticeSent($key->id ?? throw new \LogicException('A stored key has an id.'), $now);
                ++$sent;
            } catch (\Throwable $e) {
                $this->logger->warning('oauth.api_key_created_email_failed', ['keyId' => (string) $key->id, 'exception' => $e]);
            }
        }

        return $sent;
    }
}
