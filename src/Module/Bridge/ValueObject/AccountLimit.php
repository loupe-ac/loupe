<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The usage windows of one account and what they say about new work. */
final readonly class AccountLimit
{
    /** @param list<WindowLimit> $windows */
    public function __construct(
        public string $account,
        public array $windows,
    ) {
    }

    public function blocked(): bool
    {
        return null !== $this->cause();
    }

    /** The latest reset of the blocking windows. Null when the account is not blocked. */
    public function releaseAt(): ?\DateTimeImmutable
    {
        $release = null;
        foreach ($this->windows as $window) {
            if ($window->state->blocks() && (null === $release || $window->reading->resetsAt > $release)) {
                $release = $window->reading->resetsAt;
            }
        }

        return $release;
    }

    /** Null when the account is not blocked. */
    public function cause(): ?AccountLimitCause
    {
        $cause = null;
        foreach ($this->windows as $window) {
            if (WindowLimitState::AtLimit === $window->state) {
                return AccountLimitCause::Limit;
            }
            if (WindowLimitState::AtStop === $window->state) {
                $cause = AccountLimitCause::Stop;
            }
        }

        return $cause;
    }
}
