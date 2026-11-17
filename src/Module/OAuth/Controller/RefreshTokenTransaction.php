<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use Doctrine\DBAL\Connection;
use League\Bundle\OAuth2ServerBundle\Controller\TokenController;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs a refresh in one transaction. League revokes the old pair, then issues
 * and stores the new one, in separate writes. A request that dies between them
 * would leave the old token revoked and no successor. The commit comes only
 * with a 200 answer, so any other outcome leaves the old token as it was.
 * The grant is read from the parsed PSR body, as League reads it, so a JSON
 * body gets the transaction too.
 */
#[AsDecorator('league.oauth2_server.controller.token')]
final readonly class RefreshTokenTransaction
{
    public function __construct(
        #[AutowireDecorated]
        private TokenController $inner,
        private Connection $connection,

        #[Autowire(service: 'league.oauth2_server.factory.psr_http')]
        private HttpMessageFactoryInterface $psrRequests,
    ) {
    }

    public function indexAction(Request $request): Response
    {
        if (!$this->isRefresh($request)) {
            return $this->inner->indexAction($request);
        }

        $this->connection->beginTransaction();

        try {
            $response = $this->inner->indexAction($request);
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        if (Response::HTTP_OK === $response->getStatusCode()) {
            $this->connection->commit();
        } else {
            $this->connection->rollBack();
        }

        return $response;
    }

    private function isRefresh(Request $request): bool
    {
        $body = (array) $this->psrRequests->createRequest($request)->getParsedBody();

        return 'refresh_token' === ($body['grant_type'] ?? null);
    }
}
