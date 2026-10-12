<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Why a bridge cannot claim or settle a work request. */
enum WorkRequestRefusal
{
    case UnknownBridge;

    case NotFound;

    case CapabilityMissing;

    case AlreadyClaimed;

    case ClaimLost;

    case CardHeld;

    /** The error code the bridge reads. */
    public function code(): string
    {
        return match ($this) {
            self::UnknownBridge => 'unknown_bridge',
            self::NotFound => 'work_request_not_found',
            self::CapabilityMissing => 'capability_missing',
            self::AlreadyClaimed => 'already_claimed',
            self::ClaimLost => 'claim_lost',
            self::CardHeld => 'card_held',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::NotFound => 404,
            self::AlreadyClaimed, self::ClaimLost, self::CardHeld => 409,
            self::UnknownBridge, self::CapabilityMissing => 422,
        };
    }
}
