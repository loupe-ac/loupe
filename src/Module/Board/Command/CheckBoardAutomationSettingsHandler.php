<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\BoardAutomation;

/** Reads the fingerprint of the saved automation settings, which an open form compares with its own. */
final readonly class CheckBoardAutomationSettingsHandler
{
    public function __construct(
        private BoardAutomation $automation,
    ) {
    }

    public function __invoke(CheckBoardAutomationSettingsCommand $command): string
    {
        return $this->automation->settingsOf($command->project)->fingerprint();
    }
}
