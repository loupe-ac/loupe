<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Command;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\Console\SendApiKeyExpiryNoticesConsoleCommand;
use App\Module\OAuth\Command\CreateApiKeyCommand;
use App\Module\OAuth\Command\CreateApiKeyHandler;
use App\Module\OAuth\Command\SendApiKeyExpiryNoticesCommand;
use App\Module\OAuth\Command\SendApiKeyExpiryNoticesHandler;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Entity\ApiKeyExpiry;
use App\Module\OAuth\Scheduler\SendApiKeyExpiryNoticesTask;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\ScheduledTasks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

final class ApiKeyEmailsTest extends KernelTestCase
{
    private const string NOW = '2026-11-01 12:00:00';

    private EntityManagerInterface $em;
    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $container->set(ClockInterface::class, new MockClock(self::NOW));
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = new OAuthScenario($container)->createUser('api-key-mail-owner@example.com');
    }

    public function test_creating_a_key_mails_the_owner_without_the_whole_key(): void
    {
        $handler = static::getContainer()->get(CreateApiKeyHandler::class);
        self::assertInstanceOf(CreateApiKeyHandler::class, $handler);

        $view = $handler(new CreateApiKeyCommand($this->owner, 'CI <deploy>', ApiKeyExpiry::NinetyDays));

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('api-key-mail-owner@example.com', $email->getTo()[0]->getAddress());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('CI &lt;deploy&gt;', $html);
        self::assertStringContainsString($view->key->displayStart, $html);
        self::assertStringContainsString('30 Jan 2027', $html);
        self::assertStringContainsString('/account/api-keys', $html);
        self::assertStringNotContainsString($view->secret, $html);
        self::assertStringNotContainsString($view->secret, (string) $email->getTextBody());
        self::assertStringNotContainsString($view->secret, $email->getSubject() ?? '');
    }

    public function test_the_mail_of_a_key_with_no_end_says_it_never_ends(): void
    {
        $handler = static::getContainer()->get(CreateApiKeyHandler::class);
        self::assertInstanceOf(CreateApiKeyHandler::class, $handler);

        $handler(new CreateApiKeyCommand($this->owner, 'Dashboard', ApiKeyExpiry::Never));

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('never ends', (string) $email->getHtmlBody());
    }

    public function test_the_sweep_mails_each_key_once_inside_the_seven_day_window(): void
    {
        $soon = $this->persistKey('Soon', '+5 days');
        $edge = $this->persistKey('Edge', '+7 days');
        $late = $this->persistKey('Late', '+8 days');
        $never = $this->persistKey('Never', null);
        $ended = $this->persistKey('Ended', '-1 day');
        $revoked = $this->persistKey('Revoked', '+3 days');
        $revoked->revokedAt = new \DateTimeImmutable(self::NOW);
        $told = $this->persistKey('Told', '+2 days');
        $told->expiryNoticeSentAt = new \DateTimeImmutable('2026-10-30');
        $this->em->flush();
        $ids = [$soon->id, $edge->id];
        $this->em->clear();

        self::assertSame(2, $this->sweep());
        $this->em->clear();

        self::assertQueuedEmailCount(2);
        $bodies = array_map(static fn ($message): string => $message instanceof Email ? (string) $message->getHtmlBody() : '', self::getMailerMessages());
        self::assertStringContainsString('Soon', $bodies[0].$bodies[1]);
        self::assertStringContainsString('Edge', $bodies[0].$bodies[1]);
        self::assertStringNotContainsString('Late', $bodies[0].$bodies[1]);
        foreach ($ids as $id) {
            self::assertNotNull($this->em->find(ApiKey::class, $id)?->expiryNoticeSentAt);
        }
        foreach ([$late, $never, $ended, $revoked] as $untouched) {
            self::assertNull($this->em->find(ApiKey::class, $untouched->id)?->expiryNoticeSentAt, $untouched->name);
        }

        self::assertSame(0, $this->sweep());
        self::assertQueuedEmailCount(2);
    }

    public function test_the_console_command_runs_the_sweep(): void
    {
        $this->persistKey('Soon', '+2 days');
        $this->em->clear();
        $command = static::getContainer()->get(SendApiKeyExpiryNoticesConsoleCommand::class);
        self::assertInstanceOf(SendApiKeyExpiryNoticesConsoleCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1', $tester->getDisplay());
        self::assertQueuedEmailCount(1);
    }

    public function test_the_sweep_runs_every_hour_on_the_default_schedule(): void
    {
        self::assertSame(
            '23 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[SendApiKeyExpiryNoticesTask::class] ?? null,
        );
    }

    private function sweep(): int
    {
        $handler = static::getContainer()->get(SendApiKeyExpiryNoticesHandler::class);
        self::assertInstanceOf(SendApiKeyExpiryNoticesHandler::class, $handler);

        return $handler(new SendApiKeyExpiryNoticesCommand());
    }

    private function persistKey(string $name, ?string $expiresIn): ApiKey
    {
        $secret = ApiKey::PREFIX.bin2hex(random_bytes(32));
        $key = new ApiKey(
            $this->owner,
            $name,
            ApiKey::hashOf($secret),
            ApiKey::displayStartOf($secret),
            null === $expiresIn ? null : new \DateTimeImmutable(self::NOW)->modify($expiresIn),
            new \DateTimeImmutable(self::NOW),
        );
        $this->em->persist($key);
        $this->em->flush();

        return $key;
    }
}
