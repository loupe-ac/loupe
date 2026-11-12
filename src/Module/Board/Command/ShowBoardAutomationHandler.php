<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\BoardAutomation;
use App\Module\Workflow\Contract\ApprovalRules;

final readonly class ShowBoardAutomationHandler
{
    public function __construct(
        private BoardAutomation $automation,
        private ApprovalRules $approvalRules,
    ) {
    }

    public function __invoke(ShowBoardAutomationCommand $command): ShowBoardAutomationView
    {
        $settings = $this->automation->settingsOf($command->project);

        return new ShowBoardAutomationView(
            $settings->enabled,
            $settings->stuckDelayMinutes,
            $this->approvalRules->coversConflictResolution($command->project->requireId()),
        );
    }
}
