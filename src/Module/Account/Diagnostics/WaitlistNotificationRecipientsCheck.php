<?php

declare(strict_types=1);

namespace App\Module\Account\Diagnostics;

use App\Module\Account\Service\WaitlistNotificationRecipients;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/**
 * The flags page saves any text, so a typo in the list would otherwise show
 * only as a missing email. One row names every entry that is not an address,
 * because a check returns one row.
 */
final readonly class WaitlistNotificationRecipientsCheck implements DiagnosticInterface
{
    public function __construct(
        private WaitlistNotificationRecipients $recipients,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 12;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        ['valid' => $valid, 'invalid' => $invalid] = $this->recipients->read();

        if ([] !== $invalid) {
            return new Diagnostic(
                'waitlist_notification_recipients',
                DiagnosticState::Warning,
                'account.system_status.waitlist_notification_recipients.invalid',
                ['%count%' => \count($invalid), '%entries%' => implode(', ', $invalid)],
            );
        }

        if ([] === $valid) {
            return new Diagnostic('waitlist_notification_recipients', DiagnosticState::Ok, 'account.system_status.waitlist_notification_recipients.off');
        }

        return new Diagnostic(
            'waitlist_notification_recipients',
            DiagnosticState::Ok,
            'account.system_status.waitlist_notification_recipients.ok',
            ['%count%' => \count($valid)],
        );
    }
}
