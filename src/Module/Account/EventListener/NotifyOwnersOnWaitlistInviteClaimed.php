<?php

declare(strict_types=1);

namespace App\Module\Account\EventListener;

use App\Module\Account\Event\UserRegistered;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\WaitlistOwnerNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Runs before ProvisionTrialOnUserRegistered, whose throw stops dispatch. It
 * swallows its own failures, so the account always gets the later listeners.
 */
#[AsEventListener(priority: 20)]
final readonly class NotifyOwnersOnWaitlistInviteClaimed
{
    public function __construct(
        private WaitlistEntryRepository $waitlistEntries,
        private WaitlistOwnerNotifier $ownerNotifier,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UserRegistered $event): void
    {
        try {
            $entry = $this->waitlistEntries->findOneClaimedByEmail($event->user->email);
            if (null !== $entry) {
                $this->ownerNotifier->claimed($entry, $event->user, $event->provider);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('account.waitlist_notification_failed', [
                'kind' => 'claimed',
                'userId' => (string) $event->user->id,
                'exception' => $e::class,
            ]);
        }
    }
}
