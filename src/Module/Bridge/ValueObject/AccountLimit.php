<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The usage windows of one account and what they say about new work. */
final readonly class AccountLimit
{
    /**
     * @param list<WindowLimit> $windows
     * @param int               $stopAt  the percentage of use at which the account stops taking new work
     */
    public function __construct(
        public string $account,
        public array $windows,
        public int $stopAt,
    ) {
    }

    public function blocked(): bool
    {
        return null !== $this->cause();
    }

    /** The latest reset of the blocking windows. Null when the account is not blocked. */
    public function releaseAt(): ?\DateTimeImmutable
    {
        return $this->releasingWindow()?->reading->resetsAt;
    }

    /** The blocking window with the latest reset, a window at its limit first on a tie. Null when the account is not blocked. */
    public function releasingWindow(): ?WindowLimit
    {
        $releasing = null;
        foreach ($this->windows as $window) {
            if (!$window->state->blocks()) {
                continue;
            }
            $later = null === $releasing || $window->reading->resetsAt > $releasing->reading->resetsAt;
            $tie = null !== $releasing
                && $window->reading->resetsAt == $releasing->reading->resetsAt
                && WindowLimitState::AtLimit === $window->state
                && WindowLimitState::AtLimit !== $releasing->state;
            if ($later || $tie) {
                $releasing = $window;
            }
        }

        return $releasing;
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
