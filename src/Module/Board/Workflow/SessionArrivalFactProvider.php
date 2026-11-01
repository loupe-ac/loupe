<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class SessionArrivalFactProvider implements FactProvider
{
    #[\Override]
    public function factsClass(): string
    {
        return SessionArrivalFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new SessionArrivalFacts($card->arrivedInSession);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof SessionArrivalFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->arrivedInSession;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
