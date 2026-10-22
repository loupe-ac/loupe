<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Repository\SubscriptionRepository;
use App\Site\SiteLinks;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends the welcome email of one redeemed beta invite, once. The due date on the
 * invite is the guard: clearing it and queueing the email commit together, so a
 * failed queue write leaves the invite due for the next trigger or the sweep.
 * That holds while the `jobs` transport shares the ORM's database connection.
 * The email promises free access, so it waits while the redeemer holds no comp.
 */
class BetaWelcomeEmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly BetaInviteRepository $betaInvites,
        private readonly SubscriptionRepository $subscriptions,
        private readonly Connection $connection,
        private readonly SiteLinks $siteLinks,
        private readonly UrlGeneratorInterface $router,
        private readonly LoggerInterface $logger,

        #[Autowire(param: 'app.mailer.from_address')]
        private readonly string $mailerFromAddress,

        #[Autowire(param: 'app.mailer.from_name')]
        private readonly string $mailerFromName,
    ) {
    }

    /**
     * Never throws: a failure is logged, and the access the invite granted stays.
     */
    public function sendIfDue(BetaInvite $invite): void
    {
        $redeemer = $invite->redeemedBy;
        if (null === $invite->id || null === $redeemer || !$redeemer->isVerified() || null === $invite->welcomeEmailDueAt) {
            return;
        }

        try {
            if (!$this->subscriptions->holdsCurrentComp($redeemer, new \DateTimeImmutable())) {
                $this->logger->info('billing.beta_welcome_email_withheld', [
                    'betaInviteId' => (string) $invite->id,
                    'userId' => (string) $redeemer->id,
                ]);

                return;
            }

            $discordUrl = $this->siteLinks->discordUrl();
            $email = new TemplatedEmail()
                ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
                ->to($redeemer->email)
                ->subject($this->translator->trans('billing.email.beta_welcome.subject'))
                ->htmlTemplate('@Billing/email/beta_welcome.html.twig')
                ->context([
                    'appUrl' => $this->router->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    'discordUrl' => '' === $discordUrl ? null : $discordUrl,
                ]);

            $this->connection->transactional(function () use ($invite, $email): void {
                if ($this->betaInvites->claimWelcomeEmail($invite)) {
                    $this->mailer->send($email);
                }
            });
        } catch (\Throwable $e) {
            $this->logger->warning('billing.beta_welcome_email_failed', [
                'betaInviteId' => (string) $invite->id,
                'userId' => (string) $redeemer->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $invite->welcomeEmailDueAt = null;
    }
}
