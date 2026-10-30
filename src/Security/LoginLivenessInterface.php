<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Uid\Uuid;

/**
 * Answers which CLI login an access token belongs to, and whether a login can
 * still refresh. A login is one `loupe login` on one machine.
 */
interface LoginLivenessInterface
{
    /** The login of the refresh token that was issued with the access token, or null when none is tagged. */
    public function loginOfAccessToken(string $accessTokenId): ?Uuid;

    /**
     * The logins among the given ones that hold no refresh token that is
     * neither revoked nor expired. A login with no tagged token counts as
     * expired.
     *
     * @param list<Uuid> $loginIds
     *
     * @return list<Uuid>
     */
    public function expired(array $loginIds): array;
}
