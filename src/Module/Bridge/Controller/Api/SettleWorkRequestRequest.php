<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\UsageLimitHandBack;
use App\Module\Bridge\ValueObject\WorkRequestState;
use Symfony\Component\Uid\Uuid;

/**
 * The result of a bridge for one claimed work request. The fields carry no
 * constraints and accept any JSON value, because a validation failure answers
 * without the error code that the bridge reads. The methods check them instead.
 *
 * A bridge gives a request back with the state open, the cause usage-limit, the
 * account, the reading that showed the limit, the session and whether the run
 * did any work.
 */
final class SettleWorkRequestRequest
{
    public const string CAUSE_USAGE_LIMIT = 'usage-limit';

    /** @var list<string> */
    private const array CAUSES = [self::CAUSE_USAGE_LIMIT];

    public function __construct(
        public mixed $claimToken = null,
        public mixed $state = null,
        public mixed $reason = null,
        public mixed $cause = null,
        public mixed $account = null,
        public mixed $reading = null,
        public mixed $sessionId = null,
        public mixed $hasAssistantTurn = null,
    ) {
    }

    /** Null unless the state is done, refused or open. */
    public function state(): ?WorkRequestState
    {
        $state = \is_string($this->state) ? WorkRequestState::tryFrom($this->state) : null;

        return \in_array($state, [WorkRequestState::Done, WorkRequestState::Refused, WorkRequestState::Open], true) ? $state : null;
    }

    public function claimToken(): ?Uuid
    {
        return \is_string($this->claimToken) && Uuid::isValid($this->claimToken) ? Uuid::fromString($this->claimToken) : null;
    }

    public function hasValidReason(): bool
    {
        $reason = $this->reason();

        return (null === $this->reason || \is_string($this->reason))
            && (null === $reason || 1 === preg_match(WorkRequest::REASON_PATTERN, $reason));
    }

    /** Trimmed, and null when blank. */
    public function reason(): ?string
    {
        $reason = \is_string($this->reason) ? trim($this->reason) : '';

        return '' === $reason ? null : $reason;
    }

    /** Whether the cause is one that a bridge gives. */
    public function hasValidCause(): bool
    {
        return \is_string($this->cause) && \in_array($this->cause, self::CAUSES, true);
    }

    /** Null unless every field of the hand-back is well formed. */
    public function handBack(): ?UsageLimitHandBack
    {
        $reading = $this->reading;
        if (!\is_string($this->account) || 1 !== preg_match(BridgeAccountInput::NAME_PATTERN, $this->account)
            || !\is_array($reading) || \is_array($this->sessionId) || !\is_bool($this->hasAssistantTurn)) {
            return null;
        }

        $window = $reading['window'] ?? null;
        $used = $reading['usedPercent'] ?? null;
        $resetsAt = $reading['resetsAt'] ?? null;
        $limited = $reading['limited'] ?? null;
        $source = $reading['source'] ?? null;
        $session = $this->sessionId;
        if (!\is_string($window) || 1 !== preg_match(BridgeAccountReadingInput::WINDOW_PATTERN, $window)
            || (null !== $used && (!\is_int($used) && !\is_float($used) || $used < 0 || $used > 200))
            || !\is_bool($limited)
            || !\is_string($source) || '' === trim($source) || mb_strlen(trim($source)) > BridgeAccountReadingInput::MAX_SOURCE_LENGTH
            || (null !== $session && (!\is_string($session) || !Uuid::isValid($session)))) {
            return null;
        }

        $reset = self::time($resetsAt);
        if (null === $reset || $reset > new \DateTimeImmutable(BridgeAccountReadingInput::MAX_RESET_LEAD, new \DateTimeZone('UTC'))) {
            return null;
        }

        return new UsageLimitHandBack(
            account: $this->account,
            window: $window,
            usedPercent: null === $used ? null : (float) $used,
            resetsAt: $reset,
            limited: $limited,
            source: trim($source),
            sessionId: null === $session ? null : Uuid::fromString($session),
            hasAssistantTurn: $this->hasAssistantTurn,
        );
    }

    /** An ISO 8601 date and time, in UTC. Null for anything else, so a relative phrase never parses. */
    private static function time(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
