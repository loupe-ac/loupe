<?php

declare(strict_types=1);

namespace App\Module\Account\Security;

use App\Api\Problem\ApiPaths;
use App\Api\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Renders the API firewall's access-denied (403) responses as JSON. A request that
 * authenticated but lacks the required scope role — e.g. an MCP-scoped token used on a
 * site-review endpoint — would otherwise get the framework's default HTML error page;
 * API clients (the embeddable widget included) need a machine-readable error code to tell
 * "wrong token type" apart from other 403s such as an unbound token.
 *
 * A /api/v1 path answers Problem JSON, and any other path keeps the flat error body.
 */
final class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    #[\Override]
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        $problem = new ApiProblem(Response::HTTP_FORBIDDEN, 'credential_not_allowed', 'Credential not allowed', 'This credential cannot call this route.');

        if (!ApiPaths::usesProblemJson($request->getPathInfo())) {
            return new JsonResponse($problem->toLegacyBody(), Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($problem->toProblemBody(), Response::HTTP_FORBIDDEN, ['Content-Type' => 'application/problem+json']);
    }
}
