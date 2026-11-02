<?php

declare(strict_types=1);

namespace App\Module\OAuth\Security;

use App\Api\Problem\ApiPaths;
use App\Api\Problem\ApiProblem;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Module\OAuth\Scope\ApiScope;
use App\Security\AuthenticatedCredential;
use App\Security\BearerToken;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * Accepts a bearer token that starts with the API key prefix, and no other. The
 * key carries the public scope and nothing else. The OAuth authenticator skips
 * this prefix, so one request never has two claimants.
 *
 * It sets no entry point. The OAuth authenticator answers a request that
 * brings no credential.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public const string KEY_ATTRIBUTE = 'apiKeyId';

    /** The use time is written at most this often per key. */
    private const string USE_INTERVAL = '5 minutes';

    public function __construct(
        private readonly ApiKeyRepository $apiKeys,
        private readonly ClockInterface $clock,
        private readonly Auditor $auditor,
    ) {
    }

    public static function credentialId(Uuid $keyId): string
    {
        return 'api-key:'.$keyId->toRfc4122();
    }

    #[\Override]
    public function supports(Request $request): bool
    {
        return str_starts_with(BearerToken::of($request) ?? '', ApiKey::PREFIX);
    }

    #[\Override]
    public function authenticate(Request $request): Passport
    {
        $key = $this->apiKeys->findByKeyHash(ApiKey::hashOf(BearerToken::of($request) ?? ''));
        if (null === $key || $key->isRevoked()) {
            throw new ApiKeyRefused(ApiKeyRefused::INVALID);
        }
        if ($key->isExpiredAt($this->clock->now())) {
            throw new ApiKeyRefused(ApiKeyRefused::EXPIRED);
        }

        $keyId = $key->id ?? throw new \LogicException('a stored key always has an id');
        $owner = $key->owner;
        $passport = new SelfValidatingPassport(new UserBadge($owner->getUserIdentifier(), static fn (): User => $owner));
        $passport->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential(
            self::credentialId($keyId),
            [ApiScope::Public->role()],
        ));
        $passport->setAttribute(self::KEY_ATTRIBUTE, $keyId);

        return $passport;
    }

    #[\Override]
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        $credential = $passport->getAttribute(AuthenticatedCredential::ATTRIBUTE);
        if (!$credential instanceof AuthenticatedCredential) {
            throw new \LogicException('credential missing on passport after authentication.');
        }

        $token = new PostAuthenticationToken($user, $firewallName, [...$user->getRoles(), ...$credential->roles]);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, $credential);
        $token->setAttribute(self::KEY_ATTRIBUTE, $passport->getAttribute(self::KEY_ATTRIBUTE));

        return $token;
    }

    /** Runs after the user checker, so a key of a suspended owner is never stamped. */
    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $keyId = $token->getAttribute(self::KEY_ATTRIBUTE);
        if ($keyId instanceof Uuid) {
            $now = $this->clock->now();
            $this->apiKeys->recordUse($keyId, $now, $now->modify('-'.self::USE_INTERVAL));
        }

        return null;
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $code = $exception instanceof ApiKeyRefused ? $exception->problemCode : ApiKeyRefused::INVALID;
        $this->auditor->record(
            'oauth.api_key_authentication_failed',
            AuditOutcome::Refused,
            ['reason' => $code],
            category: Auditor::CATEGORY_SECURITY,
        );

        $problem = ApiKeyRefused::EXPIRED === $code
            ? new ApiProblem(Response::HTTP_UNAUTHORIZED, $code, 'API key expired', 'This API key is past its end date.')
            : new ApiProblem(Response::HTTP_UNAUTHORIZED, $code, 'Invalid credentials', 'The credentials are missing, wrong or revoked.');

        if (!ApiPaths::usesProblemJson($request->getPathInfo())) {
            return new JsonResponse($problem->toLegacyBody(), $problem->status);
        }

        return new JsonResponse($problem->toProblemBody(), $problem->status, ['Content-Type' => 'application/problem+json']);
    }
}
