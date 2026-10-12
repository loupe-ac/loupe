<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Module\Artifact\Entity\Artifact;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The content domain of the instance. Each artifact gets one sub-address of it,
 * so two artifacts never share a browser origin.
 */
final readonly class ArtifactContentHost
{
    private const string LABEL_PATTERN = '[a-f0-9]{32}';

    /** Null when the instance has no content domain. */
    public ?string $host;

    public function __construct(
        #[Autowire(env: 'default::ARTIFACT_CONTENT_HOST')]
        ?string $configured,
    ) {
        $host = strtolower(trim($configured ?? ''));
        $this->host = '' === $host ? null : $host;
    }

    public function labelFor(Artifact $artifact): string
    {
        return $this->labelOfId((string) $artifact->id);
    }

    public function labelOfId(string $artifactId): string
    {
        return strtolower(str_replace('-', '', $artifactId));
    }

    public function originFor(string $label): ?string
    {
        return null === $this->host ? null : 'https://'.$label.'.'.$this->host;
    }

    /** The label of a request host such as `<label>.<content host>`, or null for any other host. */
    public function labelOfRequestHost(string $requestHost): ?string
    {
        if (null === $this->host) {
            return null;
        }

        $requestHost = strtolower($requestHost);
        $suffix = '.'.$this->host;
        if (!str_ends_with($requestHost, $suffix)) {
            return null;
        }

        $label = substr($requestHost, 0, -\strlen($suffix));

        return 1 === preg_match('/^'.self::LABEL_PATTERN.'$/', $label) ? $label : null;
    }

    /** True for the content domain and any sub-address of it, valid label or not. */
    public function ownsHost(string $requestHost): bool
    {
        if (null === $this->host) {
            return false;
        }

        $requestHost = strtolower($requestHost);

        return $requestHost === $this->host || str_ends_with($requestHost, '.'.$this->host);
    }
}
