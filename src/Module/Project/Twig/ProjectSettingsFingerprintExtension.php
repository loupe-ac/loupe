<?php

declare(strict_types=1);

namespace App\Module\Project\Twig;

use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectSettingsFingerprint;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** A project edit form renders the fingerprint of its settings as the state it was loaded at. */
final class ProjectSettingsFingerprintExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProjectSettingsFingerprint $fingerprint,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('project_settings_fingerprint', $this->fingerprint->of(...)),
        ];
    }
}
