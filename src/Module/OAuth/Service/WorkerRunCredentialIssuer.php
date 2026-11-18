<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\WorkerRunCredential;
use App\Module\OAuth\Repository\WorkerRunCredentialRepository;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\Project\Entity\Project;
use App\Security\IssuedWorkerRunCredential;
use App\Security\WorkerRunCredentialIssuerInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Mints the access token of one worker launch, the way MintPreviewTokenHandler
 * mints a preview token: the grant carries `mcp`, `worker-run` and one project,
 * it has no refresh token, and it expires after a day at the latest.
 *
 * The scopes skip finalizeScopes(), which limits a grant to the scopes its
 * client registered. The `loupe-cli` client does not list `worker-run`, so no
 * sign-in flow can ask for it, and this is the only place that issues it.
 */
#[AsAlias(WorkerRunCredentialIssuerInterface::class)]
final readonly class WorkerRunCredentialIssuer implements WorkerRunCredentialIssuerInterface
{
    public const string TTL = '+24 hours';
    public const string CLIENT_ID = 'loupe-cli';

    public function __construct(
        private WorkerRunCredentialRepository $workerRunCredentials,
        private EntityManagerInterface $em,
        private Auditor $auditor,

        #[Autowire(service: 'league.oauth2_server.repository.access_token')]
        private AccessTokenRepositoryInterface $accessTokens,

        #[Autowire(service: 'league.oauth2_server.repository.scope')]
        private ScopeRepositoryInterface $scopes,

        #[Autowire(service: 'league.oauth2_server.repository.client')]
        private ClientRepositoryInterface $clients,

        // The same two the bundle reads, because it exposes its key as no
        // service or parameter of its own.
        #[Autowire(env: 'resolve:OAUTH_PRIVATE_KEY')]
        private string $privateKeyPath,

        #[Autowire(env: 'OAUTH_PRIVATE_KEY_PASSPHRASE')]
        private string $privateKeyPassphrase,
    ) {
    }

    #[\Override]
    public function issue(User $owner, Project $project, Uuid $bridgeId, Uuid $runKey, Uuid $sessionId): IssuedWorkerRunCredential
    {
        $userId = $owner->id ?? throw new \LogicException('a persisted user always has an id.');
        $projectId = $project->id ?? throw new \LogicException('a persisted project always has an id.');
        $expiresAt = new \DateTimeImmutable(self::TTL);

        $identifiers = [ApiScope::Mcp->value, ApiScope::WorkerRun->value, GrantedScope::projectScope($projectId)];
        if (null === GrantedScope::fromScopes($identifiers)) {
            throw new \LogicException('the run credential scopes do not form a valid grant.');
        }

        $client = $this->clients->getClientEntity(self::CLIENT_ID)
            ?? throw new \LogicException(self::CLIENT_ID.' is not registered. A migration registers it.');
        $granted = array_map(
            fn (string $identifier): ScopeEntityInterface => $this->scopes->getScopeEntityByIdentifier($identifier)
                ?? throw new \LogicException('no scope named '.$identifier.'.'),
            $identifiers,
        );

        $jwt = $this->em->wrapInTransaction(function () use ($client, $granted, $userId, $expiresAt, $owner, $project, $bridgeId, $runKey, $sessionId): string {
            $token = $this->accessTokens->getNewToken($client, $granted, $userId->toRfc4122());
            $token->setIdentifier(bin2hex(random_bytes(WorkerRunCredential::ACCESS_TOKEN_ID_LENGTH / 2)));
            $token->setExpiryDateTime($expiresAt);
            $this->accessTokens->persistNewAccessToken($token);
            // The third argument switches off league's key permission check, as
            // the bundle does for its own key.
            $token->setPrivateKey(new CryptKey($this->privateKeyPath, '' !== $this->privateKeyPassphrase ? $this->privateKeyPassphrase : null, false));

            $this->em->persist(new WorkerRunCredential($token->getIdentifier(), $owner, $project, $bridgeId, $runKey, $sessionId, $expiresAt));
            $this->em->flush();

            return $token->toString();
        });

        $this->auditor->record(
            'oauth.worker_run_credential_issued',
            AuditOutcome::Success,
            [
                'projectId' => $projectId->toRfc4122(),
                'bridgeId' => $bridgeId->toRfc4122(),
                'runKey' => $runKey->toRfc4122(),
                'sessionId' => $sessionId->toRfc4122(),
            ],
            new AuditSubject('project', $projectId->toRfc4122()),
        );

        return new IssuedWorkerRunCredential('' !== $jwt ? $jwt : throw new \LogicException('the minted token is empty.'), $expiresAt);
    }

    #[\Override]
    public function revokeForRun(Project $project, Uuid $bridgeId, Uuid $runKey): int
    {
        return $this->workerRunCredentials->revokeForRun($project, $bridgeId, $runKey);
    }
}
