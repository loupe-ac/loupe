<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Service\ProjectSettingsFingerprint;

/** The current fingerprint of the settings that the project edit forms show. */
final readonly class ShowProjectSettingsFingerprintHandler
{
    public function __construct(
        private ProjectSettingsFingerprint $fingerprint,
    ) {
    }

    public function __invoke(ShowProjectSettingsFingerprintCommand $command): string
    {
        return $this->fingerprint->of($command->project);
    }
}
