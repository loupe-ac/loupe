<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Entity\WaitlistJoinSource;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Routing\PinnedUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells the owners that a person joined the waitlist or claimed an invite.
 *
 * Both callers have already committed their work, and one of them runs outside
 * any try block, so nothing thrown in here may reach them. The log context
 * carries no address, because the address is the visitor's data.
 */
final readonly class WaitlistOwnerNotifier
{
    public function __construct(
        private WaitlistNotificationRecipients $recipients,
        private WaitlistEntryRepository $waitlistEntries,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private PinnedUrlGenerator $urlGenerator,
        private LoggerInterface $logger,

        #[Autowire(param: 'app.mailer.from_address')]
        private string $mailerFromAddress,

        #[Autowire(param: 'app.mailer.from_name')]
        private string $mailerFromName,

        #[Autowire(param: 'kernel.default_locale')]
        private string $defaultLocale,
    ) {
    }

    public function joined(WaitlistEntry $entry, WaitlistJoinSource $source, bool $rejoined): void
    {
        $this->notify(
            'joined',
            $entry,
            fn (): array => [
                'visitorEmail' => $entry->email,
                'how' => $rejoined ? 'rejoin' : $source->value,
                'picks' => $entry->picks,
                'other' => $entry->otherPick,
                'waitingCount' => $this->waitlistEntries->countWaiting(),
                'adminUrl' => $this->urlGenerator->generate('app_admin_waitlist_list'),
            ],
        );
    }

    public function claimed(WaitlistEntry $entry, User $user, ?SocialProvider $provider): void
    {
        $this->notify(
            'claimed',
            $entry,
            function () use ($entry, $user, $provider): array {
                $claimedAt = $entry->inviteClaimedAt ?? new \DateTimeImmutable();
                $invitedAt = $entry->invitedAt ?? $claimedAt;

                return [
                    'visitorEmail' => $entry->email,
                    'method' => $provider->value ?? 'form',
                    'invitedOn' => $invitedAt->format('Y-m-d'),
                    'days' => (int) $invitedAt->diff($claimedAt)->days,
                    'waitingCount' => $this->waitlistEntries->countWaiting(),
                    'adminUrl' => $this->urlGenerator->generate('app_admin_users_detail', ['id' => (string) $user->id]),
                ];
            },
        );
    }

    /** @param \Closure(): array<string, mixed> $buildContext */
    private function notify(string $kind, WaitlistEntry $entry, \Closure $buildContext): void
    {
        try {
            $addresses = $this->recipients->read()['valid'];
            if ([] === $addresses) {
                return;
            }

            $context = $buildContext();
            $subject = $this->translator->trans('account.email.waitlist_owner.'.$kind.'.subject', [], null, $this->defaultLocale);

            foreach ($addresses as $address) {
                try {
                    $this->mailer->send(
                        new TemplatedEmail()
                            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
                            ->to($address)
                            ->subject($subject)
                            ->locale($this->defaultLocale)
                            ->htmlTemplate('@Account/email/waitlist_owner_'.$kind.'.html.twig')
                            ->context($context),
                    );
                } catch (\Throwable $e) {
                    $this->logFailure($kind, $entry, $e);
                }
            }
        } catch (\Throwable $e) {
            $this->logFailure($kind, $entry, $e);
        }
    }

    private function logFailure(string $kind, WaitlistEntry $entry, \Throwable $e): void
    {
        $this->logger->warning('account.waitlist_notification_failed', [
            'kind' => $kind,
            'entryId' => (string) $entry->id,
            'exception' => $e::class,
        ]);
    }
}
