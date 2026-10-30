<?php

declare(strict_types=1);

namespace App\Module\Workflow\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class WorkflowInstallFlags implements InstallFlagDefaultsInterface
{
    /** Merges the evaluations of one card that wait in the queue together. */
    public const string FLAG_MERGE_QUEUED_EVALUATIONS = 'workflow.merge_queued_evaluations';

    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // On: a fresh install has no worker of an older build. An instance that upgrades gets the flag off from a migration.
        yield new InstallFlagDefault(self::FLAG_MERGE_QUEUED_EVALUATIONS, FeatureFlagType::Bool, true);
    }
}
