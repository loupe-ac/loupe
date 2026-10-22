<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Module\Account\Entity\User;
use App\Module\Billing\Command\SendDueBetaWelcomeEmailsCommand;
use App\Module\Billing\Command\SendDueBetaWelcomeEmailsHandler;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Service\BetaWelcomeEmailSender;
use App\Tests\Support\BetaWelcomeEmails;
use App\Tests\Support\RecordingMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendDueBetaWelcomeEmailsHandlerTest extends KernelTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_retries_an_email_whose_first_queue_write_failed(): void
    {
        $invite = $this->redeemed('sweep-retry', verified: true);
        $failing = new class implements MailerInterface {
            #[\Override]
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new \RuntimeException('queue is down');
            }
        };
        $this->sender($failing)->sendIfDue($invite);
        self::assertNotNull($this->reload($invite)->welcomeEmailDueAt, 'the failed write leaves the invite due');

        $mailer = new RecordingMailer();
        $this->handler($mailer)(new SendDueBetaWelcomeEmailsCommand());

        self::assertCount(1, $mailer->sent);
        self::assertNull($this->reload($invite)->welcomeEmailDueAt);
    }

    public function test_a_second_pass_sends_nothing_more(): void
    {
        $this->redeemed('sweep-twice', verified: true);
        $mailer = new RecordingMailer();
        $handler = $this->handler($mailer);

        $handler(new SendDueBetaWelcomeEmailsCommand());
        $handler(new SendDueBetaWelcomeEmailsCommand());

        self::assertCount(1, $mailer->sent);
    }

    public function test_it_skips_an_unverified_redeemer(): void
    {
        $invite = $this->redeemed('sweep-unverified', verified: false);
        $guard = $this->redeemed('sweep-guard', verified: true);
        $mailer = new RecordingMailer();

        $this->handler($mailer)(new SendDueBetaWelcomeEmailsCommand());

        // The guard invite proves the pass ran, so the empty result for the other is real.
        self::assertCount(1, $mailer->sent);
        self::assertSame('sweep-guard@example.com', $this->recipient($mailer));
        self::assertNotNull($this->reload($invite)->welcomeEmailDueAt);
        self::assertNull($this->reload($guard)->welcomeEmailDueAt);
    }

    public function test_it_skips_an_invite_with_no_due_date_and_one_the_old_code_redeemed(): void
    {
        $cleared = $this->redeemed('sweep-cleared', verified: true);
        $cleared->welcomeEmailDueAt = null;
        $old = $this->redeemed('sweep-old', verified: true);
        $old->welcomeEmailDueAt = null;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $guard = $this->redeemed('sweep-guard2', verified: true);
        $mailer = new RecordingMailer();

        $this->handler($mailer)(new SendDueBetaWelcomeEmailsCommand());

        self::assertCount(1, $mailer->sent);
        self::assertSame('sweep-guard2@example.com', $this->recipient($mailer));
        self::assertNull($this->reload($guard)->welcomeEmailDueAt);
    }

    private function recipient(RecordingMailer $mailer): string
    {
        $email = $mailer->sent[0] ?? null;
        self::assertInstanceOf(TemplatedEmail::class, $email);

        return $email->getTo()[0]->getAddress();
    }

    private function handler(MailerInterface $mailer): SendDueBetaWelcomeEmailsHandler
    {
        return new SendDueBetaWelcomeEmailsHandler(
            self::getContainer()->get(BetaInviteRepository::class),
            $this->sender($mailer),
        );
    }

    private function sender(MailerInterface $mailer): BetaWelcomeEmailSender
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return BetaWelcomeEmails::sender(self::getContainer(), $mailer, $translator);
    }

    private function redeemed(string $name, bool $verified): BetaInvite
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: $name, email: $name.'@example.com', password: 'x');
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
}
