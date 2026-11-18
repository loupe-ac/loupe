<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Workflow\Contract\OutsideFacts;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowCardChangeRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Module\Workflow\Service\FactProviders;
use App\Module\Workflow\Service\WorkflowSweepRecord;
use App\Module\Workflow\Service\WorkflowSweepSettings;
use App\Service\BuildIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The backstop for a card change that no event reported. Answers how many evaluations it queued, and queues none while the previous sweep still waits.
 * With the change-driven flag on, it queues only the marked cards and the cards of the providers that read an outside answer. Off, and in the deploy pass, it queues every open bound card and reports each change that the marks missed.
 * The first sweep under a new build is the deploy pass: its checks report each change that the marks missed.
 */
final readonly class SweepWorkflowCardsHandler
{
    public function __construct(
        private WorkflowBindingRepository $workflowBindings,
        private WorkflowCardChangeRepository $workflowCardChanges,
        private FactProviders $providers,
        private WorkflowSweepSettings $settings,
        private EvaluationTrigger $trigger,
        private WorkflowSweepRecord $record,
        private BuildIdentity $build,
        private ClockInterface $clock,

        #[Autowire(service: 'messenger.transport.card_sweep')]
        private TransportInterface $lowPriorityTransport,
    ) {
    }

    public function __invoke(SweepWorkflowCardsCommand $command): int
    {
        if ($this->lowPriorityTransport instanceof MessageCountAwareInterface && $this->lowPriorityTransport->getMessageCount() > 0) {
            return 0;
        }

        $version = $this->build->version;
        $deployPass = null !== $version && $this->record->build() !== $version;
        $marked = $this->settings->changeDrivenSweep() && !$deployPass;
        $cardIds = $marked ? $this->markedCardIds() : $this->workflowBindings->findOpenBoundCardIds();
        $this->trigger->inBackground($cardIds, self::class, null, !$marked);
        if ($deployPass) {
            $this->record->recordBuild($version);
            $this->record->recordFull(\DateTimeImmutable::createFromInterface($this->clock->now()));
        }

        return \count($cardIds);
    }

    /** @return list<string> */
    private function markedCardIds(): array
    {
        $this->workflowCardChanges->expandProjectMarks();
        $this->workflowCardChanges->deleteStale();
        $always = [];
        foreach ($this->providers->byClass as $provider) {
            if ($provider instanceof OutsideFacts) {
                $always[] = $provider->alwaysSweptCardsSql();
            }
        }

        return $this->workflowCardChanges->findSweptCardIds($always);
    }
}
