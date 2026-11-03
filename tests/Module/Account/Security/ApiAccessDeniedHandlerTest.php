<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Security;

use App\Module\Account\Security\ApiAccessDeniedHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ApiAccessDeniedHandlerTest extends TestCase
{
    public function test_a_current_api_path_gets_a_credential_not_allowed_problem(): void
    {
        $response = new ApiAccessDeniedHandler()->handle(Request::create('/api/v1/bridge/card-holds'), new AccessDeniedException());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('credential_not_allowed', $body['code']);
        self::assertSame('https://loupe.ac/docs/reference/api/errors#credential_not_allowed', $body['type']);
        self::assertSame('Credential not allowed', $body['title']);
        self::assertSame(403, $body['status']);
        self::assertNotSame('', $body['detail']);
    }

    public function test_an_old_api_path_keeps_insufficient_scope(): void
    {
        $response = new ApiAccessDeniedHandler()->handle(Request::create('/api/live-review/comments'), new AccessDeniedException());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('{"error":"insufficient_scope"}', $response->getContent());
    }
}
