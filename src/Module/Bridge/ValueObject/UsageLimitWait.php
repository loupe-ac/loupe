<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/**
 * The accounts whose usage limits hold an open work request. The first account
 * to come free takes the work, so the account that releases first speaks for
 * the wait.
 */
final readonly class UsageLimitWait
{
    public AccountLimit $releasing;

    public WindowLimit $window;

    public \DateTimeImmutable $resetsAt;

    /** @param non-empty-list<AccountLimit> $accounts blocked accounts, each with a release time */
    public function __construct(
        public array $accounts,
    ) {
        $releasing = $accounts[0];
        foreach ($accounts as $account) {
            if (($account->releaseAt() ?? throw new \LogicException('A blocked account has a release time.')) < ($releasing->releaseAt() ?? throw new \LogicException('A blocked account has a release time.'))) {
                $releasing = $account;
            }
        }
        $this->releasing = $releasing;
        $this->window = $releasing->releasingWindow() ?? throw new \LogicException('A blocked account has a releasing window.');
        $this->resetsAt = $this->window->reading->resetsAt;
    }

    /** The hard limit when the releasing window is at its limit, else the stop value. */
    public function cause(): AccountLimitCause
    {
        return WindowLimitState::AtLimit === $this->window->state ? AccountLimitCause::Limit : AccountLimitCause::Stop;
    }
}
