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

    case ProjectHeld;

    /** The request waits for a time that has not come. */
    case Waiting;

    /** The account the bridge named is at its limit or at its stop value. */
    case AccountBlocked;

    /** The claim names no account, or an account the bridge does not list for the kind of the request. */
    case AccountUnknown;

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
            self::ProjectHeld => 'project_held',
            self::Waiting => 'waiting',
            self::AccountBlocked => 'account_blocked',
            self::AccountUnknown => 'account_unknown',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::NotFound => 404,
            self::AlreadyClaimed, self::ClaimLost, self::CardHeld, self::ProjectHeld, self::Waiting, self::AccountBlocked => 409,
            self::UnknownBridge, self::CapabilityMissing, self::AccountUnknown => 422,
        };
    }
}
