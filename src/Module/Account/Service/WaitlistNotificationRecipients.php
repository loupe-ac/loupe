<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The addresses that get waitlist emails. The flag is free text, so the send
 * and the Diagnostics check share one rule for what counts as an address.
 */
final readonly class WaitlistNotificationRecipients
{
    public const string FLAG = 'waitlist.notification_emails';

    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    /** @return array{valid: list<string>, invalid: list<string>} */
    public function read(): array
    {
        return self::parse($this->featureFlags->getStringValue(self::FLAG, ''));
    }

    /** @return array{valid: list<string>, invalid: list<string>} */
    public static function parse(string $value): array
    {
        $tokens = preg_split('/[\s,]+/', strtolower(trim($value)), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        $valid = [];
        $invalid = [];
        foreach (array_unique($tokens) as $token) {
            if (false === filter_var($token, \FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $token;

                continue;
            }
            $valid[] = $token;
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }
}
