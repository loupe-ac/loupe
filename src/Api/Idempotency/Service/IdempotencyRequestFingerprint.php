<?php

declare(strict_types=1);

namespace App\Api\Idempotency\Service;

use Symfony\Component\HttpFoundation\Request;

/** The hash that tells a retry from a second, different request under the same key. */
final readonly class IdempotencyRequestFingerprint
{
    public function of(Request $request): string
    {
        return hash('sha256', implode("\n", [
            $request->getMethod(),
            $request->getPathInfo(),
            (string) $request->getQueryString(),
            $request->getContent(),
        ]));
    }
}
