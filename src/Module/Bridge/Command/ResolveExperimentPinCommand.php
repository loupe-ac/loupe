<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * The variant a card runs with in one experiment. The candidate is one of the
 * variants. The weights name each variant with its weight, and are null when
 * the bridge sent no valid list. The metrics are the keys the experiment
 * declares, and are null when the bridge sent no valid list. The headline names
 * the goal and the guards of the experiment, and is null when the bridge sent
 * none or an invalid one. The covers are the work kinds the experiment treats,
 * and are null when the bridge sent no valid list. The baseline is the variant
 * the others are compared against, and is one of the variants.
 */
final readonly class ResolveExperimentPinCommand
{
    public function __construct(
        public User $owner,
        public string $handle,
        public Uuid $cardId,
        public string $experiment,
        public string $candidate,
        /** @var non-empty-list<string> */
        public array $variants,
        public string $baseline,
        /** @var list<array{name: string, weight: int}>|null */
        public ?array $weights = null,
        /** @var list<string>|null */
        public ?array $metrics = null,
        /** @var array{goal?: array{metric: string, better?: string}, guards?: list<string>, controls?: list<array{metric: string, variant: string, expect: string, share: float}>}|null */
        public ?array $headline = null,
        /** @var non-empty-list<string>|null */
        public ?array $covers = null,
    ) {
    }
}
