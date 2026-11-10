<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/**
 * The picker session of one owner and one project. It first holds the state of
 * a trip to GitHub, either an App install or a sign-in. After the sign-in it
 * holds the user token, encrypted, with the time of the sign-in.
 */
final readonly class PendingGitHubInstall
{
    public function __construct(
        public string $projectId,
        /** Set while an App install is in progress, and only then. */
        public ?string $installState = null,
        public ?int $installationId = null,
        public ?string $authorizeState = null,
        public ?string $codeVerifier = null,
        /** The path the owner wants ticked, such as `acme/api`. */
        public ?string $repository = null,
        public ?string $encryptedToken = null,
        public ?int $signedInAt = null,
    ) {
    }

    public function hasSignedIn(): bool
    {
        return null !== $this->encryptedToken && null !== $this->signedInAt;
    }

    /** The S256 challenge of the verifier, base64url with no padding. */
    public function codeChallenge(): ?string
    {
        if (null === $this->codeVerifier) {
            return null;
        }

        return rtrim(strtr(base64_encode(hash('sha256', $this->codeVerifier, true)), '+/', '-_'), '=');
    }
}
