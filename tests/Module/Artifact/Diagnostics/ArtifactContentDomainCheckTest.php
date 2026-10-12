<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Diagnostics;

use App\Module\Artifact\Diagnostics\ArtifactContentDomainCheck;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Service\ArtifactContentHost;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class ArtifactContentDomainCheckTest extends TestCase
{
    public function test_it_reports_nothing_while_artifacts_are_off(): void
    {
        self::assertNull($this->check(false, null)());
    }

    public function test_it_warns_when_artifacts_are_on_and_no_host_is_set(): void
    {
        $diagnostic = $this->check(true, '')();

        self::assertNotNull($diagnostic);
        self::assertSame('artifact_content_domain', $diagnostic->key);
        self::assertSame(DiagnosticState::Warning, $diagnostic->state);
    }

    public function test_it_is_ok_when_a_host_is_set(): void
    {
        $diagnostic = $this->check(true, 'content.example')();

        self::assertNotNull($diagnostic);
        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
    }

    private function check(bool $enabled, ?string $host): ArtifactContentDomainCheck
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('isEnabled')->willReturnCallback(static fn (string $name): bool => ArtifactInstallFlags::FLAG_ENABLED === $name && $enabled);

        return new ArtifactContentDomainCheck($flags, new ArtifactContentHost($host));
    }
}
