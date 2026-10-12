<?php

declare(strict_types=1);

namespace App\Module\Artifact\Security;

use App\Module\Artifact\Service\ArtifactContentHost;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/** Selects the firewall that reads no session, for every request to the content domain. */
final readonly class ArtifactContentHostRequestMatcher implements RequestMatcherInterface
{
    public function __construct(
        private ArtifactContentHost $contentHost,
    ) {
    }

    #[\Override]
    public function matches(Request $request): bool
    {
        return $this->contentHost->ownsHost($request->getHost());
    }
}
