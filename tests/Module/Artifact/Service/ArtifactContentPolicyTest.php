<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service;

use App\Module\Artifact\Service\ArtifactAllowedOrigins;
use App\Module\Artifact\Service\ArtifactContentPolicy;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class ArtifactContentPolicyTest extends TestCase
{
    public function test_the_content_domain_keeps_its_origin_and_may_be_framed_by_the_app_only(): void
    {
        self::assertSame(
            "sandbox allow-scripts allow-forms allow-popups allow-pointer-lock allow-same-origin; frame-ancestors https://app.example:8443; default-src 'self' data: blob: 'unsafe-inline' 'unsafe-eval' https://cdn.example.com; form-action 'self'",
            $this->policy('https://app.example:8443/some/path')->forResponse(true),
        );
    }

    public function test_the_app_domain_never_gets_allow_same_origin(): void
    {
        self::assertSame(
            "sandbox allow-scripts allow-forms allow-popups allow-pointer-lock; frame-ancestors 'self'; default-src 'self' data: blob: 'unsafe-inline' 'unsafe-eval' https://cdn.example.com; form-action 'self'",
            $this->policy('https://app.example')->forResponse(false),
        );
    }

    private function policy(string $appUrl): ArtifactContentPolicy
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getStringValue')->willReturn("https://cdn.example.com bad;value 'unsafe-eval'");

        return new ArtifactContentPolicy(new ArtifactAllowedOrigins($flags), $appUrl);
    }
}
