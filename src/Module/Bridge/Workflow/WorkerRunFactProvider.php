<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FingerprintValue;

final readonly class WorkerRunFactProvider extends BridgeFactProvider
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return WorkerRunFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'worker-runs';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $since = $this->workerRuns->findOpenWorkSinceOfCard($card->id);

        $ruleSince = $this->workerRuns->findOpenWorkSinceOfCard($card->id, ruleOwnedOnly: true);

        return new WorkerRunFacts(array_map(strval(...), array_keys($since)), $since, array_map(strval(...), array_keys($ruleSince)), $ruleSince);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof WorkerRunFacts || throw new \LogicException('The provider fingerprints its own facts.');

        $active = FingerprintValue::sorted($facts->activeKinds);
        $rule = FingerprintValue::sorted($facts->activeRuleKinds);

        return $active === $rule ? $active : [$active, $rule];
    }
}
