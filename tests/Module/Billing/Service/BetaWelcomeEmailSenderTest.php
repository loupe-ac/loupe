<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Service\BetaWelcomeEmailSender;
use App\Tests\Support\BetaWelcomeEmails;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LogLevel;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BetaWelcomeEmailSenderTest extends KernelTestCase
{
    public function test_a_due_invite_of_a_verified_user_queues_one_email_and_clears_the_date(): void
    {
        $invite = $this->redeemedInvite(verified: true);
        $mailer = new RecordingMailer();

        $this->sender($mailer)->sendIfDue($invite);

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('welcome-tester@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('billing.email.beta_welcome.subject', $email->getSubject());
        self::assertSame('@Billing/email/beta_welcome.html.twig', $email->getHtmlTemplate());
        self::assertSame(BetaWelcomeEmails::DISCORD, $email->getContext()['discordUrl']);
        self::assertMatchesRegularExpression('#^https?://[^/]+/#', $email->getContext()['appUrl']);
        self::assertNull($this->reload($invite)->welcomeEmailDueAt);
    }

    public function test_without_a_discord_link_the_context_carries_none(): void
    {
        $invite = $this->redeemedInvite(verified: true);
        $mailer = new RecordingMailer();

        $this->sender($mailer, discord: '')->sendIfDue($invite);

        self::assertCount(1, $mailer->sent);
        self::assertNull($mailer->sent[0]->getContext()['discordUrl']);
    }

    public function test_a_second_call_queues_nothing_more(): void
    {
        $invite = $this->redeemedInvite(verified: true);
        $mailer = new RecordingMailer();
        $sender = $this->sender($mailer);

        $sender->sendIfDue($invite);
        $sender->sendIfDue($this->reload($invite));

        self::assertCount(1, $mailer->sent);
    }

    public function test_a_stale_copy_of_a_cleared_invite_queues_nothing(): void
    {
        $invite = $this->redeemedInvite(verified: true);
        $stale = clone $invite;
        $mailer = new RecordingMailer();
        $sender = $this->sender($mailer);

        $sender->sendIfDue($invite);
        $sender->sendIfDue($stale);

        self::assertCount(1, $mailer->sent);
    }

    public function test_an_unverified_user_gets_nothing_and_the_invite_stays_due(): void
    {
        $invite = $this->redeemedInvite(verified: false);
        $mailer = new RecordingMailer();

        $this->sender($mailer)->sendIfDue($invite);

        self::assertSame([], $mailer->sent);
        self::assertNotNull($this->reload($invite)->welcomeEmailDueAt);
    }

    public function test_an_invite_with_no_due_date_gets_nothing(): void
    {
        $invite = $this->redeemedInvite(verified: true);
        $invite->welcomeEmailDueAt = null;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $mailer = new RecordingMailer();

        $this->sender($mailer)->sendIfDue($invite);

        self::assertSame([], $mailer->sent);
    }

    public function test_an_invite_nobody_redeemed_gets_nothing(): void
    {
        self::bootKernel();
        [$invite] = BetaInvite::issue(null);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($invite);
        $em->flush();
        $mailer = new RecordingMailer();

        $this->sender($mailer)->sendIfDue($invite);

        self::assertSame([], $mailer->sent);
    }

    public function test_a_failing_mailer_keeps_the_due_date_and_a_second_call_queues_the_email(): void
    {
        $invite = $this->redeemedInvite(verified: true);
        $failing = new class implements MailerInterface {
            #[\Override]
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new \RuntimeException('queue is down');
            }
        };
        $logger = new RecordingLogger();

        $this->sender($failing, logger: $logger)->sendIfDue($invite);

        self::assertNotNull($this->reload($invite)->welcomeEmailDueAt);
        self::assertSame(
            [[LogLevel::WARNING, 'billing.beta_welcome_email_failed']],
            array_map(static fn (array $record): array => [$record['level'], $record['message']], $logger->records),
        );
        self::assertSame('queue is down', $logger->records[0]['context']['error']);

        $mailer = new RecordingMailer();
        $this->sender($mailer)->sendIfDue($this->reload($invite));

        self::assertCount(1, $mailer->sent);
        self::assertNull($this->reload($invite)->welcomeEmailDueAt);
    }

    private function redeemedInvite(bool $verified): BetaInvite
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Welcome Tester', email: 'welcome-tester@example.com', password: 'x');
        if ($verified) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
        }
        [$invite] = BetaInvite::issue(null);
        $invite->redeem($user);
        $em->persist($user);
        $em->persist($invite);
        $em->flush();

        return $invite;
    }

    private function reload(BetaInvite $invite): BetaInvite
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(BetaInvite::class, $invite->id) ?? throw new \LogicException('persisted above');
    }

    private function sender(MailerInterface $mailer, string $discord = BetaWelcomeEmails::DISCORD, ?RecordingLogger $logger = null): BetaWelcomeEmailSender
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return BetaWelcomeEmails::sender(self::getContainer(), $mailer, $translator, $discord, $logger ?? new RecordingLogger());
    }
}
