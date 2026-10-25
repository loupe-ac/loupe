<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

enum PushUnavailableReason: string
{
    case FlagOff = 'flag-off';
    case PublicKeyMissing = 'public-key-missing';
    case PrivateKeyMissing = 'private-key-missing';
    case PublicKeyMalformed = 'public-key-malformed';
    case PrivateKeyMalformed = 'private-key-malformed';
    case PairMismatched = 'pair-mismatched';

    /**
     * The diagnostic sentence for this reason. It names the bad key and never prints one.
     *
     * @return non-empty-string
     */
    public function detailKey(): string
    {
        return match ($this) {
            self::FlagOff => 'notifications.system_status.web_push.flag_off',
            self::PublicKeyMissing => 'notifications.system_status.web_push.public_key_missing',
            self::PrivateKeyMissing => 'notifications.system_status.web_push.private_key_missing',
            self::PublicKeyMalformed => 'notifications.system_status.web_push.public_key_malformed',
            self::PrivateKeyMalformed => 'notifications.system_status.web_push.private_key_malformed',
            self::PairMismatched => 'notifications.system_status.web_push.pair_mismatched',
        };
    }
}
