<?php

declare(strict_types=1);

namespace App\Module\Notification\Diagnostics;

use App\Module\Notification\Service\PushAvailability;
use App\Module\Notification\Service\PushUnavailableReason;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/** Reads the same answer that gates the pages and the sender, and prints no key. */
final readonly class WebPushCheck implements DiagnosticInterface
{
    private const string AVAILABLE = 'notifications.system_status.web_push.available';

    public function __construct(
        private PushAvailability $availability,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 10;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        $reason = $this->availability->reason();

        return new Diagnostic(
            'web_push',
            match ($reason) {
                null, PushUnavailableReason::FlagOff => DiagnosticState::Ok,
                default => DiagnosticState::Failed,
            },
            $reason?->detailKey() ?? self::AVAILABLE,
        );
    }
}
