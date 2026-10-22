<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Account\Command\VerifyEmailCommand;
use App\Module\Account\Command\VerifyEmailHandler;
use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Tests\Support\BetaWelcomeEmails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;

final class SendBetaWelcomeOnEmailVerifiedTest extends KernelTestCase
{
    public function test_verifying_the_address_sends_one_email_per_redeemed_invite(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Late Verifier', email: 'late-verifier@example.com', password: 'x');
        $token = $user->generateEmailVerificationToken();
        [$first] = BetaInvite::issue(null);
        [$second] = BetaInvite::issue(null);
        $first->redeem($user);
        $second->redeem($user);
        $em->persist($user);
        BetaWelcomeEmails::persistComp($em, $user);
        $em->persist($first);
        $em->persist($second);
        $em->flush();

        self::assertCount(0, $this->welcomeEmailsTo('late-verifier@example.com'), 'an unverified address gets none yet');

        $verified = self::getContainer()->get(VerifyEmailHandler::class)(new VerifyEmailCommand($token));

        self::assertNotNull($verified);
        self::assertCount(2, $this->welcomeEmailsTo('late-verifier@example.com'));
        $em->clear();
        foreach ([$first, $second] as $invite) {
            self::assertNull($em->find(BetaInvite::class, $invite->id)?->welcomeEmailDueAt);
        }
    }

    /** @return list<TemplatedEmail> */
    private function welcomeEmailsTo(string $address): array
    {
        $emails = [];
        foreach (self::getMailerEvents() as $event) {
            $message = $event->getMessage();
            if ($event instanceof MessageEvent && $event->isQueued() && $message instanceof TemplatedEmail
                && 'Welcome to the Loupe beta' === $message->getSubject()
                && $address === $message->getTo()[0]->getAddress()) {
                $emails[] = $message;
            }
        }

        return $emails;
    }
}
