<?php

declare(strict_types=1);

namespace App\Module\OAuth\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** An API key the server will not accept, with the code the client reads. */
final class ApiKeyRefused extends AuthenticationException
{
    public const string INVALID = 'invalid_credentials';

    public const string EXPIRED = 'api_key_expired';

    public function __construct(
        public readonly string $problemCode,
    ) {
        parent::__construct('API key refused: '.$problemCode);
    }
}
