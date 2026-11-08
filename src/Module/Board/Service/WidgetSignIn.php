<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\OAuth\Repository\PreviewTokenRepository;
use App\Security\AuthenticatedCredential;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** Tells a preview token from a pop-up sign-in on the server, so the widget cannot choose. */
final readonly class WidgetSignIn
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private PreviewTokenRepository $previewTokens,
    ) {
    }

    public function isPreviewToken(): bool
    {
        $accessTokenId = AuthenticatedCredential::of($this->tokenStorage->getToken())?->accessTokenId;

        return null !== $accessTokenId && $this->previewTokens->isLive($accessTokenId);
    }
}
