<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\RevokeOwnPreviewTokenCommand;
use App\Module\OAuth\Command\RevokeOwnPreviewTokenHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** The handler touches only the signed-in user's own tokens, so no voter is needed. */
#[CsrfToken('revoke-preview-token')]
#[Route(
    '/account/connected-apps/preview-tokens/{accessTokenId}/revoke',
    name: 'app_account_preview_token_revoke',
    requirements: ['accessTokenId' => '[^/]+'],
    defaults: ['returnTo' => self::RETURN_TO_LIST],
    methods: ['POST'],
)]
#[Route(
    '/account/connected-apps/preview-tokens/{accessTokenId}/revoke/to-card',
    name: 'app_account_preview_token_revoke_to_card',
    requirements: ['accessTokenId' => '[^/]+'],
    defaults: ['returnTo' => self::RETURN_TO_CARD],
    methods: ['POST'],
)]
final class RevokePreviewTokenController extends AppController
{
    public const string RETURN_TO_LIST = 'list';
    public const string RETURN_TO_CARD = 'card';

    public function __construct(
        private readonly RevokeOwnPreviewTokenHandler $revokeOwnPreviewToken,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(string $accessTokenId, string $returnTo): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $row = ($this->revokeOwnPreviewToken)(new RevokeOwnPreviewTokenCommand($user, $accessTokenId));
        if (null === $row) {
            return $this->redirectToRoute('app_account_connected_apps');
        }

        $this->addFlash('success', $this->translator->trans('oauth.connected_apps.preview_tokens.flash.revoked'));

        if (self::RETURN_TO_CARD === $returnTo && null !== $row->cardId) {
            return $this->redirectToRoute('app_board_card', ['projectId' => (string) $row->projectId, 'cardId' => (string) $row->cardId]);
        }

        return $this->redirectToRoute('app_account_connected_apps');
    }
}
