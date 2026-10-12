<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The Content-Security-Policy of one served file. The sandbox drops
 * allow-same-origin on the app domain, so a script there runs with an opaque
 * origin. A content-domain address is a separate site, so it keeps its own origin.
 */
final readonly class ArtifactContentPolicy
{
    private string $appOrigin;

    public function __construct(
        private ArtifactAllowedOrigins $allowedOrigins,

        #[Autowire(param: 'app.url')]
        string $appUrl,
    ) {
        $parts = parse_url($appUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'localhost');
        $this->appOrigin = isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }

    public function forResponse(bool $onContentHost): string
    {
        $sandbox = 'sandbox allow-scripts allow-forms allow-popups allow-pointer-lock'.($onContentHost ? ' allow-same-origin' : '');
        $sources = implode(' ', ["'self'", 'data:', 'blob:', "'unsafe-inline'", "'unsafe-eval'", ...$this->allowedOrigins->origins()]);

        return \sprintf(
            "%s; frame-ancestors %s; default-src %s; form-action 'self'",
            $sandbox,
            $onContentHost ? $this->appOrigin : "'self'",
            $sources,
        );
    }
}
