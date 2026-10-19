<?php

declare(strict_types=1);

namespace App\Module\Readiness\Workflow;

use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** The owner approved the report of the latest discovery run of the card. */
final readonly class DiscoveryApproved implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.discovery.approved';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.readiness';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [DiscoveryFacts::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        return DiscoveryRunState::Done === $facts->get(DiscoveryFacts::class)->state;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_discovery_approved' : 'workflow.waiting.card_discovery_approved');
    }
}
