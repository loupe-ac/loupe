<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
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
    ) {
    }

    public const string STUCK_DELAY_INVALID = 'board.automation.error.stuck_delay_invalid';

    public function __invoke(SaveBoardAutomationSettingsCommand $command): void
    {
        if ($command->stuckDelayMinutes < BoardAutomationSettings::MIN_STUCK_DELAY_MINUTES || $command->stuckDelayMinutes > BoardAutomationSettings::MAX_STUCK_DELAY_MINUTES) {
            throw new DomainErrors(['stuckDelayMinutes' => self::STUCK_DELAY_INVALID]);
        }

        $settings = $this->automation->settingsForUpdate($command->project);
        $wasEnabled = $settings->enabled;
        $settings->enabled = $command->enabled;
        $delayChanged = $settings->stuckDelayMinutes !== $command->stuckDelayMinutes;
        $settings->stuckDelayMinutes = $command->stuckDelayMinutes;
        $this->em->flush();
        if ($delayChanged) {
            // A longer delay can clear a Stuck marker, and a shorter one can add it.
            $this->cards->makeMarkersDue($command->project, $this->clock->now());
        }
        $this->events->dispatch(new BoardAutomationSettingsSaved(
            $command->project,
            !$wasEnabled && $command->enabled,
        ));

        $this->auditor->record('board.automation_settings_saved', AuditOutcome::Success, [
            'projectId' => (string) $command->project->id,
            'enabled' => $command->enabled,
            'stuckDelayMinutes' => $settings->stuckDelayMinutes,
        ]);
    }
}
