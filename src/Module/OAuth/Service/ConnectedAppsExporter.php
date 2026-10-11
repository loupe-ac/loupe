<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\OAuth\Repository\GrantRepository;
use App\Module\OAuth\Repository\PreviewTokenRepository;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\OAuth\Widget\WidgetClient;

/** The apps that hold a live grant for the user, then the live preview tokens. Token values never leave the database. */
final readonly class ConnectedAppsExporter implements UserDataExporterInterface
{
    public function __construct(
        private GrantRepository $grants,
        private PreviewTokenRepository $previewTokens,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'connected_apps.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $userId = $user->id ?? throw new \LogicException('a persisted user always has an id');

        foreach ($this->grants->findLiveGrantsForUser($userId) as $row) {
            $granted = GrantedScope::fromScopes(explode(' ', $row['scopes']));

            yield [
                'kind' => 'grant',
                'clientId' => $row['clientId'],
                'clientName' => $row['clientName'],
                'scopes' => null === $granted ? [] : array_map(static fn (ApiScope $scope): string => $scope->value, $granted->scopes),
                'projectId' => $granted?->projectId?->toRfc4122(),
                'allProjects' => null !== $granted && $granted->allProjects,
            ];
        }

        foreach ($this->previewTokens->findLiveForUser($userId) as $token) {
            yield [
                'kind' => 'preview-token',
                'clientId' => WidgetClient::ID,
                'projectId' => $token->projectId->toRfc4122(),
                'cardId' => $token->cardId?->toRfc4122(),
                'cardNumber' => $token->cardNumber,
                'label' => $token->label,
                'expiresAt' => $token->expiresAt->format(\DATE_ATOM),
                'lastUsedAt' => $token->lastUsedAt?->format(\DATE_ATOM),
                'createdAt' => $token->createdAt->format(\DATE_ATOM),
            ];
        }
    }
}
