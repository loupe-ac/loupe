<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FingerprintValue;
use App\Module\Workflow\Contract\PersonWork;

final readonly class WorkRequestFactProvider extends BridgeFactProvider
{
    public function __construct(
        private WorkRequestRepository $workRequests,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return WorkRequestFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'work-requests';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $since = [];
        $ruleSince = [];
        foreach ($this->workRequests->findLiveForCard($card->id) as $request) {
            $since[$request->kind] ??= $request->createdAt;
            if (!PersonWork::owns($request->ruleId)) {
                $ruleSince[$request->kind] ??= $request->createdAt;
            }
        }

        return new WorkRequestFacts(array_map(strval(...), array_keys($since)), $since, array_map(strval(...), array_keys($ruleSince)), $ruleSince);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof WorkRequestFacts || throw new \LogicException('The provider fingerprints its own facts.');

        $active = FingerprintValue::sorted($facts->activeKinds);
        $rule = FingerprintValue::sorted($facts->activeRuleKinds);

        return $active === $rule ? $active : [$active, $rule];
    }
}
