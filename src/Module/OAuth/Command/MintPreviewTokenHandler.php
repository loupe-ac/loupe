<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Module\OAuth\Entity\PreviewToken;
use App\Module\OAuth\Repository\PreviewTokenRepository;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\OAuth\Widget\WidgetClient;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\AccessTokenManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Issues a widget sign-in for a preview site with no popup. The token is the
 * one the popup flow issues, with the same scopes and the same reach, but it
 * lasts 14 days and has no refresh token. Only the owner of a project may
 * mint one, as only the owner may sign in through the popup.
 *
 * A second mint under the same label replaces the first, so a preview
 * that is deployed again does not pile up tokens. The project row lock
 * makes two mints at once take turns, so only one of them stays live.
 */
final readonly class MintPreviewTokenHandler
{
    public const string TTL = '+14 days';

    public function __construct(
        private ProjectRepository $projects,
        private PreviewTokenRepository $previewTokens,
        private EntityManagerInterface $em,
        private Auditor $auditor,

        #[Autowire(service: 'league.oauth2_server.repository.access_token')]
        private AccessTokenRepositoryInterface $accessTokens,
        private AccessTokenManagerInterface $accessTokenManager,

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

    /** @throws DomainErrors */
    public function __invoke(MintPreviewTokenCommand $command): MintPreviewTokenView
    {
        $label = trim($command->label);
        if ('' === $label || mb_strlen($label) > PreviewToken::MAX_LABEL_LENGTH) {
            throw new DomainErrors(['label' => 'oauth.preview_token.error.label_invalid']);
        }

        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            throw new DomainErrors(['handle' => 'oauth.preview_token.error.project_not_found']);
        }

        if (null !== $command->cardId && !$this->previewTokens->cardBelongsToProject($command->cardId, $project)) {
            throw new DomainErrors(['card' => 'oauth.preview_token.error.card_not_found']);
        }

        $userId = $command->owner->id ?? throw new \LogicException('a persisted user always has an id.');
        $projectId = $project->id ?? throw new \LogicException('a persisted project always has an id.');
        $expiresAt = new \DateTimeImmutable(self::TTL);

        $jwt = $this->em->wrapInTransaction(function () use ($command, $label, $userId, $projectId, $project, $expiresAt): string {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            $this->previewTokens->revokeByLabel($userId, $projectId, $label);

            $client = $this->clients->getClientEntity(WidgetClient::ID)
                ?? throw new \LogicException(WidgetClient::ID.' is not registered. A migration registers it.');
            $requested = array_map(
                fn (string $identifier): ScopeEntityInterface => $this->scopes->getScopeEntityByIdentifier($identifier)
                    ?? throw new \LogicException('no scope named '.$identifier.'.'),
                [WidgetClient::SCOPE, GrantedScope::projectScope($projectId)],
            );
            $granted = $this->scopes->finalizeScopes($requested, 'authorization_code', $client, $userId->toRfc4122());

            $token = $this->accessTokens->getNewToken($client, $granted, $userId->toRfc4122());
            $token->setIdentifier(bin2hex(random_bytes(40)));
            $token->setExpiryDateTime($expiresAt);
            $this->accessTokens->persistNewAccessToken($token);
            // The third argument switches off league's key permission check, as
            // the bundle does for its own key.
            $token->setPrivateKey(new CryptKey($this->privateKeyPath, '' !== $this->privateKeyPassphrase ? $this->privateKeyPassphrase : null, false));

            $stored = $this->accessTokenManager->find($token->getIdentifier());
            if (!$stored instanceof AccessToken) {
                throw new \LogicException('the access token just issued is not stored.');
            }

            $this->em->persist(new PreviewToken($stored, $command->owner, $project, $label, $command->cardId));
            $this->em->flush();

            return $token->toString();
        });

        $this->auditor->record(
            'oauth.preview_token_minted',
            AuditOutcome::Success,
            ['projectId' => $projectId->toRfc4122(), 'label' => $label, 'cardId' => $command->cardId?->toRfc4122()],
            new AuditSubject('project', $projectId->toRfc4122()),
        );

        return new MintPreviewTokenView('' !== $jwt ? $jwt : throw new \LogicException('the minted token is empty.'), $expiresAt);
    }
}
