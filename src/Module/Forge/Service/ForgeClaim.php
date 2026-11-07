<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;

final readonly class ForgeClaim
{
    /**
     * @param ?ForgeRepository $repository null on Refused, so a refusal never reveals the owner
     * @param ?string          $movedFrom  the previous path, when this project's row changed path
     * @param bool             $tookOver   true when an installation changed a row that another source held
     */
    private function __construct(
        public ForgeClaimOutcome $outcome,
        public ?ForgeRepository $repository = null,
        public ?string $movedFrom = null,
        public bool $tookOver = false,
    ) {
    }

    public static function owned(ForgeRepository $repository): self
    {
        return new self(ForgeClaimOutcome::Owned, $repository);
    }

    public static function alreadyOwned(ForgeRepository $repository, ?string $movedFrom, bool $tookOver = false): self
    {
        return new self(ForgeClaimOutcome::AlreadyOwned, $repository, $movedFrom, $tookOver);
    }

    public static function refused(): self
    {
        return new self(ForgeClaimOutcome::Refused);
    }
}
