<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/** The usage windows of one account and what they say about new work. */
final readonly class AccountLimit
{
    /**
     * @param list<WindowLimit> $windows
     * @param int               $stopAt            the percentage of use at which the account stops taking new work
     * @param bool              $tryNow            whether an open Try now override lets the account take work, whatever its windows say
     * @param ?Uuid             $bridgeId          the bridge that holds the account, when the limit was read for one bridge
     * @param bool              $bridgeTakesTryNow whether that bridge reports the try-now capability
     */
    public function __construct(
        public string $account,
        public array $windows,
        public int $stopAt,
        public bool $tryNow = false,
        public ?Uuid $bridgeId = null,
        public bool $bridgeTakesTryNow = false,
    ) {
    }

    /** Whether the account takes no new work. An open Try now override lifts it. */
    public function blocked(): bool
    {
        return !$this->tryNow && null !== $this->cause();
    }

    /** Whether a window is at the hard limit that the tool reported. Open overrides do not change it. */
    public function atHardLimit(): bool
    {
        return array_any($this->windows, static fn (WindowLimit $window): bool => WindowLimitState::AtLimit === $window->state);
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
