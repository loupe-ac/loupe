<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** GitHub did not answer an App call usefully. The message is safe to log and never holds a key, a JWT or a token. */
final class GitHubAppApiFailed extends \RuntimeException
{
    /** GitHub refuses the rules of a branch on a plan that has no rulesets. */
    public const string PLAN_LIMITED = 'plan_limited';

    /**
     * @param ?int    $retryAfterSeconds how long GitHub asks a rate-limited caller to wait, when it says
     * @param ?string $graphqlType       the `type` of the GraphQL error that failed the call, such as `FORBIDDEN`
     * @param ?string $githubMessage     the `message` of a plan refusal, which names no secret
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?int $status = null,
        public readonly bool $rateLimited = false,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $graphqlType = null,
        public readonly ?string $githubMessage = null,
    ) {
        parent::__construct($reason);
    }
}
