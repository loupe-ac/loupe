<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\ValueObject\NamedAccountReading;

/** The latest reading of each window of each account, on the named bridges of the project owner. */
final readonly class AccountUsageFacts
{
    /** @param list<NamedAccountReading> $readings */
    public function __construct(
        public array $readings,
    ) {
    }

    /**
     * The reading of a window of `<bridge name>/<account>`, or null when there is none. An account name holds no
     * slash and a bridge name can, so the split is at the last slash.
     */
    public function reading(string $target, string $window): ?NamedAccountReading
    {
        $slash = strrpos($target, '/');
        if (false === $slash) {
            return null;
        }
        $bridgeName = substr($target, 0, $slash);
        $account = substr($target, $slash + 1);

        return array_find(
            $this->readings,
            static fn (NamedAccountReading $reading): bool => $reading->bridgeName === $bridgeName && $reading->account === $account && $reading->window === $window,
        );
    }
}
