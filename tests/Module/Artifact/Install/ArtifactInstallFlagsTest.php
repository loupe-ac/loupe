<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class ArtifactInstallFlagsTest extends TestCase
{
    public function test_the_flags_are_seeded_with_their_defaults(): void
    {
        $defaults = [];
        foreach (new ArtifactInstallFlags()->defaults(new SeedInstallFlagsCommand(50, true, false, 14, null, false, false)) as $default) {
            self::assertInstanceOf(InstallFlagDefault::class, $default);
            $defaults[$default->name] = [$default->type, $default->value];
        }

        self::assertSame([
            'artifacts.enabled' => [FeatureFlagType::Bool, false],
            'artifacts.max_version_bytes' => [FeatureFlagType::Int, 5242880],
            'artifacts.max_files' => [FeatureFlagType::Int, 50],
            'artifacts.allowed_origins' => [FeatureFlagType::String, ArtifactInstallFlags::DEFAULT_ALLOWED_ORIGINS],
        ], $defaults);
    }

    public function test_a_migration_seeds_the_allowed_origins_flag_for_an_upgraded_instance(): void
    {
        $seeded = false;
        foreach (glob(__DIR__.'/../../../../migrations/Version*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, "'artifacts.allowed_origins'") && str_contains($source, ArtifactInstallFlags::DEFAULT_ALLOWED_ORIGINS)) {
                $seeded = true;
            }
        }

        self::assertTrue($seeded);
    }
}
