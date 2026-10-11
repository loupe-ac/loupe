<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Module\Artifact\Entity\ArtifactVersion;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signs the links to the stored files of one version. A link holds the version
 * id and an expiry, and its signature covers both, so every file of the version
 * shares one signed prefix and relative references inside a page keep working.
 */
final readonly class ArtifactContentSigner
{
    public const int TIME_TO_LIVE = 3600;

    private const string PURPOSE = 'artifact-content';

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function sign(string $versionId, int $expires): string
    {
        return hash_hmac('sha256', self::PURPOSE.'|'.$versionId.'|'.$expires, $this->secret);
    }

    public function isValid(string $versionId, int $expires, string $signature): bool
    {
        if ($expires < $this->clock->now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->sign($versionId, $expires), $signature);
    }

    /** The path of one file, valid for one hour. Encoded per segment, so slashes stay. */
    public function pathFor(ArtifactVersion $version, string $path): string
    {
        $versionId = (string) $version->id;
        $expires = $this->clock->now()->getTimestamp() + self::TIME_TO_LIVE;

        return $this->urls->generate('app_artifact_content', [
            'versionId' => $versionId,
            'expires' => $expires,
            'signature' => $this->sign($versionId, $expires),
            'path' => $path,
        ]);
    }
}
