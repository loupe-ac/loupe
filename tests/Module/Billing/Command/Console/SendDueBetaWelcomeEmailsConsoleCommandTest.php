<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command\Console;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Event\MessageEvent;

final class SendDueBetaWelcomeEmailsConsoleCommandTest extends KernelTestCase
{
    public function test_it_queues_one_email_for_a_due_invite(): void
    {
        $kernel = self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Console Tester', email: 'console-welcome@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        [$invite] = BetaInvite::issue(null);
        $invite->redeem($user);
        $em->persist($user);
        $em->persist($invite);
        $em->flush();

        $tester = new CommandTester(new Application($kernel)->find('app:billing:send-due-beta-welcome-emails'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $queued = array_filter(
            self::getMailerEvents(),
            static fn (MessageEvent $event): bool => $event->isQueued()
                && $event->getMessage() instanceof TemplatedEmail
                && 'console-welcome@example.com' === $event->getMessage()->getTo()[0]->getAddress(),
        );
        self::assertCount(1, $queued);
        self::assertNull($em->find(BetaInvite::class, $invite->id)?->welcomeEmailDueAt);
    }
}
