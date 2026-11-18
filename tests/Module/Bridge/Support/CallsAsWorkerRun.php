<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Support;

use App\Module\Bridge\ValueObject\WorkerRunKey;
use App\Security\AuthenticatedCredential;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

/** Puts a run credential on the security token, as the OAuth authenticator does for the token of a worker launch. */
trait CallsAsWorkerRun
{
    private function callAsWorkerRun(Uuid $bridgeId, Uuid $runKey, Uuid $sessionId, ?Uuid $projectId = null): WorkerRunKey
    {
        $credential = new AuthenticatedCredential(
            id: 'worker-run-'.$runKey->toRfc4122(),
            roles: ['ROLE_API_MCP', 'ROLE_API_WORKER_RUN'],
            projectId: $projectId,
            workerBridgeId: $bridgeId,
            workerRunKey: $runKey,
            workerSessionId: $sessionId,
        );
        $this->setCredential($credential);

        return new WorkerRunKey($bridgeId, $runKey);
    }

    private function callWithCredential(AuthenticatedCredential $credential): void
    {
        $this->setCredential($credential);
    }

    private function callWithoutCredential(): void
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(null);
    }

    private function setCredential(AuthenticatedCredential $credential): void
    {
        $token = new PostAuthenticationToken(new InMemoryUser('worker', null, $credential->roles), 'api', $credential->roles);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, $credential);
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken($token);
    }
}
