<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

use App\Module\Inbox\Repository\InboxDecisionRepository;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

/** An inbox that is off holds no decision log, so a rule that waits on the absence of a change still reads the card. */
final readonly class DecisionChangedFactProvider implements FactProvider
{
    public function __construct(
        private InboxDecisionRepository $inboxDecisions,
        private InboxAvailability $availability,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return DecisionChangedFacts::class;
    }

    #[\Override]
    public function sourceTables(): array
    {
        return [
            'feature_flag',
            'inbox_decisions',
        ];
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        if (!$this->availability->isEnabled()) {
            return new DecisionChangedFacts(false);
        }

        return new DecisionChangedFacts($this->inboxDecisions->hasOpenChange($card->projectId, $card->id));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof DecisionChangedFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->open;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.inbox';
    }
}
