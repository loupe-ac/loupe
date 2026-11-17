<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Controller;

use App\Module\OAuth\Controller\RefreshTokenTransaction;
use Doctrine\DBAL\Connection;
use League\Bundle\OAuth2ServerBundle\Controller\TokenController;
use League\OAuth2\Server\AuthorizationServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

final class RefreshTokenTransactionTest extends TestCase
{
    private AuthorizationServer&Stub $server;
    private Connection&MockObject $connection;
    private RefreshTokenTransaction $wrapper;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
        $this->server = $this->createStub(AuthorizationServer::class);
        $this->connection = $this->createMock(Connection::class);

        $psrRequests = new PsrHttpFactory($this->psr17, $this->psr17, $this->psr17, $this->psr17);
        $inner = new TokenController(
            $this->server,
            $psrRequests,
            new HttpFoundationFactory(),
            $this->psr17,
            new EventDispatcher(),
        );

        $this->wrapper = new RefreshTokenTransaction($inner, $this->connection, $psrRequests);
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function refreshRequests(): iterable
    {
        $json = '{"grant_type":"refresh_token","refresh_token":"x"}';

        yield 'json body' => [Request::create('/oauth/token', Request::METHOD_POST, [], [], [], ['CONTENT_TYPE' => 'application/json'], $json)];
        yield 'form body' => [Request::create('/oauth/token', Request::METHOD_POST, ['grant_type' => 'refresh_token', 'refresh_token' => 'x'])];
    }

    #[DataProvider('refreshRequests')]
    public function test_a_refresh_commits_on_success(Request $request): void
    {
        $this->server->method('respondToAccessTokenRequest')->willReturn($this->psr17->createResponse(200));
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        self::assertSame(200, $this->wrapper->indexAction($request)->getStatusCode());
    }

    #[DataProvider('refreshRequests')]
    public function test_a_refused_refresh_rolls_back(Request $request): void
    {
        $this->server->method('respondToAccessTokenRequest')->willReturn($this->psr17->createResponse(400));
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->never())->method('commit');
        $this->connection->expects($this->once())->method('rollBack');

        self::assertSame(400, $this->wrapper->indexAction($request)->getStatusCode());
    }

    public function test_an_authorization_code_request_opens_no_transaction(): void
    {
        $this->server->method('respondToAccessTokenRequest')->willReturn($this->psr17->createResponse(200));
        $this->connection->expects($this->never())->method('beginTransaction');

        $request = Request::create('/oauth/token', Request::METHOD_POST, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"grant_type":"authorization_code"}');

        self::assertSame(200, $this->wrapper->indexAction($request)->getStatusCode());
    }

    public function test_an_unreadable_body_opens_no_transaction(): void
    {
        $this->server->method('respondToAccessTokenRequest')->willReturn($this->psr17->createResponse(400));
        $this->connection->expects($this->never())->method('beginTransaction');

        $request = Request::create('/oauth/token', Request::METHOD_POST, [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');

        self::assertSame(400, $this->wrapper->indexAction($request)->getStatusCode());
    }
}
