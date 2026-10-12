<?php

declare(strict_types=1);

namespace App\Module\Artifact\Diagnostics;

use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Service\ArtifactContentHost;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/** Does not look up DNS or the certificate, because that would call out from the status page. */
final readonly class ArtifactContentDomainCheck implements DiagnosticInterface
{
    public function __construct(
        private FeatureFlagService $featureFlags,
        private ArtifactContentHost $contentHost,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 14;
    }

    #[\Override]
    public function __invoke(): ?Diagnostic
    {
        if (!$this->featureFlags->isEnabled(ArtifactInstallFlags::FLAG_ENABLED)) {
            return null;
        }

        if (null === $this->contentHost->host) {
            return new Diagnostic('artifact_content_domain', DiagnosticState::Warning, 'artifact.system_status.content_domain.missing');
        }

        return new Diagnostic('artifact_content_domain', DiagnosticState::Ok, 'artifact.system_status.content_domain.configured');
    }
}
