<?php

declare(strict_types=1);

namespace App\Module\Artifact\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class ArtifactInstallFlags implements InstallFlagDefaultsInterface
{
    public const string FLAG_ENABLED = 'artifacts.enabled';
    public const string FLAG_MAX_VERSION_BYTES = 'artifacts.max_version_bytes';
    public const string FLAG_MAX_FILES = 'artifacts.max_files';
    public const string FLAG_ALLOWED_ORIGINS = 'artifacts.allowed_origins';

    public const int DEFAULT_MAX_VERSION_BYTES = 5 * 1024 * 1024;
    public const int DEFAULT_MAX_FILES = 50;
    public const string DEFAULT_ALLOWED_ORIGINS = 'https://fonts.googleapis.com https://fonts.gstatic.com https://cdn.jsdelivr.net https://unpkg.com https://cdnjs.cloudflare.com';

    // The web server refuses a body above 8 MiB and PHP keeps 100 uploads, so a higher flag cannot take effect.
    public const int CEILING_MAX_VERSION_BYTES = 7 * 1024 * 1024;
    public const int CEILING_MAX_FILES = 100;

    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Off: the operator switches it on once the content domain works.
        yield new InstallFlagDefault(self::FLAG_ENABLED, FeatureFlagType::Bool, false);
        yield new InstallFlagDefault(self::FLAG_MAX_VERSION_BYTES, FeatureFlagType::Int, self::DEFAULT_MAX_VERSION_BYTES);
        yield new InstallFlagDefault(self::FLAG_MAX_FILES, FeatureFlagType::Int, self::DEFAULT_MAX_FILES);
        yield new InstallFlagDefault(self::FLAG_ALLOWED_ORIGINS, FeatureFlagType::String, self::DEFAULT_ALLOWED_ORIGINS);
    }
}
