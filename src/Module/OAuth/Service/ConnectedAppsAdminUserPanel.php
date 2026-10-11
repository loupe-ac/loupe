<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\Account\Admin\AdminUserPanel;
use App\Module\Account\Admin\AdminUserPanelInterface;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\ListConnectedAppsCommand;
use App\Module\OAuth\Command\ListConnectedAppsHandler;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\OAuth\Twig\PreviewSignInExtension;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * OAuth's contribution to the admin user detail page: the apps the user has
 * connected, what each one reaches, and the live preview tokens.
 *
 * The panel always renders, empty state included, because "this account has
 * connected nothing" is the answer an admin most often wants and an absent
 * panel cannot give it.
 *
 * The context carries scalars only, so Account never learns an OAuth type. The
 * scope values travel as strings and the template builds the same
 * `oauth.consent.scope.*` keys the account-facing page uses.
 */
#[AsTaggedItem(priority: 5)]
final readonly class ConnectedAppsAdminUserPanel implements AdminUserPanelInterface
{
    public function __construct(
        private ListConnectedAppsHandler $listConnectedApps,
    ) {
    }

    #[\Override]
    public function panelFor(User $user): AdminUserPanel
    {
        $view = ($this->listConnectedApps)(new ListConnectedAppsCommand($user));

        $apps = [];
        foreach ($view->apps as $app) {
            $grants = [];
            foreach ($app->grants as $grant) {
                $grants[] = [
                    'scopes' => array_map(static fn (ApiScope $scope): string => $scope->value, $grant['scopes']),
                    'allProjects' => $grant['allProjects'],
                    // Null for a grant on every project, and also for one whose
                    // project has since been deleted.
                    'project' => $grant['project']?->name,
                ];
            }

            $apps[] = ['name' => $app->clientName, 'grants' => $grants];
        }

        $previewTokens = [];
        foreach ($view->previewTokens as $token) {
            $previewTokens[] = [
                'label' => $token->label,
                'cardNumber' => $token->cardNumber,
                'cardTitle' => $token->cardTitle,
                'project' => $token->projectName,
                'daysLeft' => PreviewSignInExtension::daysLeft($token->expiresAt),
            ];
        }

        return new AdminUserPanel('@OAuth/admin/connected_apps_panel.html.twig', ['apps' => $apps, 'previewTokens' => $previewTokens]);
    }
}
