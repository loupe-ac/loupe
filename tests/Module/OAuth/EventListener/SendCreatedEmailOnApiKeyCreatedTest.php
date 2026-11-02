<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\EventListener;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Event\ApiKeyCreated;
use App\Module\OAuth\EventListener\SendCreatedEmailOnApiKeyCreated;
use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Module\OAuth\Service\ApiKeyEmailSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Uid\Uuid;

final class SendCreatedEmailOnApiKeyCreatedTest extends TestCase
{
    private const string NOW = '2026-11-01 12:00:00';

    public function test_a_failed_mail_is_logged_stays_pending_and_never_reaches_the_caller(): void
    {
        $emails = $this->createStub(ApiKeyEmailSender::class);
        $emails->method('sendCreated')->willThrowException(new TransportException('queue down'));
        $apiKeys = $this->createMock(ApiKeyRepository::class);
        $apiKeys->expects($this->never())->method('markCreatedNoticeSent');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('oauth.api_key_created_email_failed');

        new SendCreatedEmailOnApiKeyCreated($emails, $apiKeys, new MockClock(self::NOW), $logger)(new ApiKeyCreated($this->key()));
    }

    public function test_it_sends_the_mail_of_the_key_and_then_stamps_it(): void
    {
        $key = $this->key();
        $emails = $this->createMock(ApiKeyEmailSender::class);
        $emails->expects($this->once())->method('sendCreated')->with($key);
        $apiKeys = $this->createMock(ApiKeyRepository::class);
        $apiKeys->expects($this->once())->method('markCreatedNoticeSent')->with($key->id, new \DateTimeImmutable(self::NOW));

        new SendCreatedEmailOnApiKeyCreated($emails, $apiKeys, new MockClock(self::NOW), new NullLogger())(new ApiKeyCreated($key));
    }

    private function key(): ApiKey
    {
        $key = new ApiKey(new User(fullName: 'Riley Chen', email: 'riley@example.com'), 'CI', str_repeat('a', 64), 'loupe_key_aaaa');
        new \ReflectionProperty(ApiKey::class, 'id')->setValue($key, Uuid::v7());

        return $key;
    }
}
