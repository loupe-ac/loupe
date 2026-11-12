<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Workflow\Contract\ApprovalRuleChanged;
use App\Module\Workflow\Contract\ApprovalRules;
use App\Module\Workflow\Contract\ApprovalRuleWriter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final readonly class SaveBoardAutomationSettingsHandler
{
    public function __construct(
        private BoardAutomation $automation,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
        private CardRepository $cards,
        private ClockInterface $clock,
        private ApprovalRules $approvalRules,
        private ApprovalRuleWriter $approvalRuleWriter,
    ) {
    }

    public const string STUCK_DELAY_INVALID = 'board.automation.error.stuck_delay_invalid';

    public const string NO_WORKFLOW = 'board.automation.error.no_workflow';

    public function __invoke(SaveBoardAutomationSettingsCommand $command): void
    {
        if (null !== $command->stuckDelayMinutes && ($command->stuckDelayMinutes < BoardAutomationSettings::MIN_STUCK_DELAY_MINUTES || $command->stuckDelayMinutes > BoardAutomationSettings::MAX_STUCK_DELAY_MINUTES)) {
            throw new DomainErrors(['stuckDelayMinutes' => self::STUCK_DELAY_INVALID]);
        }

        $projectId = $command->project->id ?? throw new \LogicException('The project is not persisted.');
        $approval = $command->approvalCoversConflictResolution;
        $approvalWrite = null !== $approval && $approval !== $this->approvalRules->coversConflictResolution($projectId) ? $approval : null;

        // The flushes inside the writers join this transaction, so one failure leaves every setting as it was.
        // The project row lock serializes saves, so a setting left out keeps the value another save just wrote.
        $result = $this->em->wrapInTransaction(function () use ($command, $projectId, $approvalWrite): ?array {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);
            if (null !== $approvalWrite && !$this->approvalRuleWriter->setCoversConflictResolution($projectId, $approvalWrite)) {
                return null;
            }

            $settings = $this->automation->settingsForUpdate($command->project);
            if (null !== $settings->id) {
                $this->em->refresh($settings);
            }
            $wasEnabled = $settings->enabled;
            $settings->enabled = $command->enabled ?? $wasEnabled;
            $stuckDelayMinutes = $command->stuckDelayMinutes ?? $settings->stuckDelayMinutes;
            $delayChanged = $settings->stuckDelayMinutes !== $stuckDelayMinutes;
            $settings->stuckDelayMinutes = $stuckDelayMinutes;
            $this->em->flush();
            if ($delayChanged) {
                // A longer delay can clear a Stuck marker, and a shorter one can add it.
                $this->cards->makeMarkersDue($command->project, $this->clock->now());
            }

            return [!$wasEnabled && $settings->enabled, $settings->enabled, $stuckDelayMinutes];
        });
        if (null === $result) {
            throw new DomainErrors(['approvalCoversConflictResolution' => self::NO_WORKFLOW]);
        }
        [$turnedOn, $enabled, $stuckDelayMinutes] = $result;

        if (null !== $approvalWrite) {
            $this->events->dispatch(new ApprovalRuleChanged($projectId));
        }
        $this->events->dispatch(new BoardAutomationSettingsSaved(
            $command->project,
            $turnedOn,
        ));

        $this->auditor->record('board.automation_settings_saved', AuditOutcome::Success, [
            'projectId' => (string) $command->project->id,
            'enabled' => $enabled,
            'stuckDelayMinutes' => $stuckDelayMinutes,
            'approvalCoversConflictResolution' => $this->approvalRules->coversConflictResolution($projectId),
        ]);
    }
}
