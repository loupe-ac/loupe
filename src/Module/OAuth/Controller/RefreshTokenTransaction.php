<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use Doctrine\DBAL\Connection;
use League\Bundle\OAuth2ServerBundle\Controller\TokenController;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs a refresh in one transaction. League revokes the old pair, then issues
 * and stores the new one, in separate writes. A request that dies between them
 * would leave the old token revoked and no successor. The commit comes only
 * with a 200 answer, so any other outcome leaves the old token as it was.
 */
#[AsDecorator('league.oauth2_server.controller.token')]
final readonly class RefreshTokenTransaction
{
    public function __construct(
        #[AutowireDecorated]
        private TokenController $inner,
        private Connection $connection,
    ) {
    }

    public function indexAction(Request $request): Response
    {
        if ('refresh_token' !== $request->request->get('grant_type')) {
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
}
