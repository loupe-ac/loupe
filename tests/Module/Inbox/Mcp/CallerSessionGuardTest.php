<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Inbox\Mcp\CallerSessionGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class CallerSessionGuardTest extends TestCase
{
    public function test_a_call_with_no_header_passes_for_any_session(): void
    {
        $this->guard(null)->requireOwnSession(Uuid::v4());
        $this->addToAssertionCount(1);
    }

    public function test_a_call_with_no_request_passes(): void
    {
        new CallerSessionGuard(new RequestStack())->requireOwnSession(Uuid::v4());
        $this->addToAssertionCount(1);
    }

    public function test_a_header_that_equals_the_session_passes(): void
    {
        $session = Uuid::v4();

        $this->guard((string) $session)->requireOwnSession($session);
        $this->addToAssertionCount(1);
    }

    public function test_a_header_that_names_another_session_is_refused(): void
    {
        try {
            $this->guard((string) Uuid::v4())->requireOwnSession(Uuid::v4());
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['sessionId' => CallerSessionGuard::NOT_CALLER], $e->errors);
        }
    }

    public function test_a_header_that_is_not_a_uuid_is_refused(): void
    {
        $this->expectException(DomainErrors::class);
        $this->guard('not-a-uuid')->requireOwnSession(Uuid::v4());
    }

    private function guard(?string $header): CallerSessionGuard
    {
        $request = Request::create('/mcp', Request::METHOD_POST);
        if (null !== $header) {
            $request->headers->set(AgentRunCause::SESSION_HEADER, $header);
        }
        $requests = new RequestStack();
        $requests->push($request);

        return new CallerSessionGuard($requests);
    }
}
