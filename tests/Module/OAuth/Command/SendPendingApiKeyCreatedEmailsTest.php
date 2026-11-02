<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Command;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\Console\SendPendingApiKeyCreatedEmailsConsoleCommand;
use App\Module\OAuth\Command\CreateApiKeyCommand;
use App\Module\OAuth\Command\CreateApiKeyHandler;
use App\Module\OAuth\Command\SendPendingApiKeyCreatedEmailsCommand;
use App\Module\OAuth\Command\SendPendingApiKeyCreatedEmailsHandler;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Entity\ApiKeyExpiry;
use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Module\OAuth\Scheduler\SendPendingApiKeyCreatedEmailsTask;
use App\Module\OAuth\Service\ApiKeyEmailSender;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\ScheduledTasks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

final class SendPendingApiKeyCreatedEmailsTest extends KernelTestCase
{
    private const string NOW = '2026-11-01 12:00:00';
    private const string AFTER_THE_GRACE = '2026-11-01 12:05:00';

    private EntityManagerInterface $em;
    private User $owner;

    protected function setUp(): void
    {
        $this->bootAt(self::NOW);
        $this->owner = new OAuthScenario(static::getContainer())->createUser('api-key-retry-owner@example.com');
    }

    public function test_a_mail_that_failed_to_queue_goes_out_on_a_later_run(): void
    {
        $emails = $this->createStub(ApiKeyEmailSender::class);
        $emails->method('sendCreated')->willThrowException(new TransportException('queue down'));
        static::getContainer()->set(ApiKeyEmailSender::class, $emails);
        $handler = static::getContainer()->get(CreateApiKeyHandler::class);
        self::assertInstanceOf(CreateApiKeyHandler::class, $handler);

        $view = $handler(new CreateApiKeyCommand($this->owner, 'CI deploy', ApiKeyExpiry::NinetyDays));

        self::assertQueuedEmailCount(0);
        $this->em->clear();
        self::assertNull($this->em->find(ApiKey::class, $view->key->id)?->createdNoticeSentAt);

        $this->bootAt(self::AFTER_THE_GRACE);

        self::assertSame(1, $this->sweep());
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('api-key-retry-owner@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('CI deploy', (string) $email->getHtmlBody());
        self::assertStringNotContainsString($view->secret, (string) $email->getHtmlBody());
        $this->em->clear();
        self::assertEquals(new \DateTimeImmutable(self::AFTER_THE_GRACE), $this->em->find(ApiKey::class, $view->key->id)?->createdNoticeSentAt);

        self::assertSame(0, $this->sweep());
        self::assertQueuedEmailCount(1);
    }

    public function test_a_mail_that_reached_the_queue_is_not_sent_again(): void
    {
        $handler = static::getContainer()->get(CreateApiKeyHandler::class);
        self::assertInstanceOf(CreateApiKeyHandler::class, $handler);

        $view = $handler(new CreateApiKeyCommand($this->owner, 'CI deploy', ApiKeyExpiry::NinetyDays));

        self::assertQueuedEmailCount(1);
        $this->em->clear();
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->em->find(ApiKey::class, $view->key->id)?->createdNoticeSentAt);

        $this->bootAt(self::AFTER_THE_GRACE);

        self::assertSame(0, $this->sweep());
        self::assertQueuedEmailCount(0);
    }

    public function test_the_sweep_leaves_a_key_inside_the_grace_period(): void
    {
        $inside = $this->persistKey('Inside', '2026-11-01 11:55:01');
        $edge = $this->persistKey('Edge', '2026-11-01 11:55:00');
        $this->em->clear();

        self::assertSame(1, $this->sweep());

        $this->em->clear();
        self::assertNull($this->em->find(ApiKey::class, $inside->id)?->createdNoticeSentAt);
        self::assertNotNull($this->em->find(ApiKey::class, $edge->id)?->createdNoticeSentAt);
    }

    public function test_the_sweep_still_tells_the_owner_of_a_revoked_key(): void
    {
        $key = $this->persistKey('Revoked', '2026-11-01 10:00:00');
        $key->revokedAt = new \DateTimeImmutable(self::NOW);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(1, $this->sweep());
        self::assertQueuedEmailCount(1);
    }

    public function test_one_failed_mail_stays_pending_and_the_others_go_out(): void
    {
        $failing = $this->persistKey('Failing', '2026-11-01 09:00:00');
        $working = $this->persistKey('Working', '2026-11-01 10:00:00');
        $this->em->clear();
        $emails = $this->createStub(ApiKeyEmailSender::class);
        $emails->method('sendCreated')->willReturnCallback(static function (ApiKey $key): void {
            if ('Failing' === $key->name) {
                throw new TransportException('queue down');
            }
        });
        $apiKeys = static::getContainer()->get(ApiKeyRepository::class);
        self::assertInstanceOf(ApiKeyRepository::class, $apiKeys);
        $handler = new SendPendingApiKeyCreatedEmailsHandler($apiKeys, $emails, new MockClock(self::NOW), new NullLogger());

        self::assertSame(1, $handler(new SendPendingApiKeyCreatedEmailsCommand()));

        $this->em->clear();
        self::assertNull($this->em->find(ApiKey::class, $failing->id)?->createdNoticeSentAt);
        self::assertNotNull($this->em->find(ApiKey::class, $working->id)?->createdNoticeSentAt);
    }

    public function test_the_console_command_runs_the_sweep(): void
    {
        $this->persistKey('Pending', '2026-11-01 10:00:00');
        $this->em->clear();
        $command = static::getContainer()->get(SendPendingApiKeyCreatedEmailsConsoleCommand::class);
        self::assertInstanceOf(SendPendingApiKeyCreatedEmailsConsoleCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1', $tester->getDisplay());
        self::assertQueuedEmailCount(1);
    }

    public function test_the_sweep_runs_every_hour_on_the_default_schedule(): void
    {
        self::assertSame(
            '41 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[SendPendingApiKeyCreatedEmailsTask::class] ?? null,
        );
    }

    /** A new kernel holds the real services and an empty mail log. The test transaction carries over. */
    private function bootAt(string $now): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();
        $container->set(ClockInterface::class, new MockClock($now));
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    private function sweep(): int
    {
        $handler = static::getContainer()->get(SendPendingApiKeyCreatedEmailsHandler::class);
        self::assertInstanceOf(SendPendingApiKeyCreatedEmailsHandler::class, $handler);

        return $handler(new SendPendingApiKeyCreatedEmailsCommand());
    }

    private function persistKey(string $name, string $createdAt): ApiKey
    {
        $secret = ApiKey::PREFIX.bin2hex(random_bytes(32));
        $key = new ApiKey(
            $this->owner,
            $name,
            ApiKey::hashOf($secret),
            ApiKey::displayStartOf($secret),
            null,
            new \DateTimeImmutable($createdAt),
        );
        $this->em->persist($key);
        $this->em->flush();

        return $key;
    }
}
