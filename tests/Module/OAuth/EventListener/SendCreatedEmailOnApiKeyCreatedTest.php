<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\EventListener;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Event\ApiKeyCreated;
use App\Module\OAuth\EventListener\SendCreatedEmailOnApiKeyCreated;
use App\Module\OAuth\Service\ApiKeyEmailSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;

final class SendCreatedEmailOnApiKeyCreatedTest extends TestCase
{
    public function test_a_failed_mail_is_logged_and_never_reaches_the_caller(): void
    {
        $emails = $this->createStub(ApiKeyEmailSender::class);
        $emails->method('sendCreated')->willThrowException(new TransportException('queue down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('oauth.api_key_created_email_failed');

        new SendCreatedEmailOnApiKeyCreated($emails, $logger)(new ApiKeyCreated($this->key()));
    }

    public function test_it_sends_the_mail_of_the_key(): void
    {
        $key = $this->key();
        $emails = $this->createMock(ApiKeyEmailSender::class);
        $emails->expects($this->once())->method('sendCreated')->with($key);

        new SendCreatedEmailOnApiKeyCreated($emails, new NullLogger())(new ApiKeyCreated($key));
    }

    private function key(): ApiKey
    {
        return new ApiKey(new User(fullName: 'Riley Chen', email: 'riley@example.com'), 'CI', str_repeat('a', 64), 'loupe_key_aaaa');
    }
}
