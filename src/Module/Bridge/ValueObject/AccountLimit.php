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

    /**
     * The windows in the order the Agents page draws them: session, week, then the others by name.
     *
     * @return list<WindowLimit>
     */
    public function orderedWindows(): array
    {
        $windows = $this->windows;
        usort($windows, static fn (WindowLimit $a, WindowLimit $b): int => [self::rank($a->reading->window), $a->reading->window] <=> [self::rank($b->reading->window), $b->reading->window]);

        return $windows;
    }

    private static function rank(string $window): int
    {
        return match ($window) {
            'session' => 0,
            'week' => 1,
            default => 2,
        };
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
