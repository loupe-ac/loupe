<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Module\Artifact\Controller\ShowArtifactContentController;
use App\Module\Artifact\Entity\ArtifactVersion;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signs the links to the stored files of one version. A link holds the version
 * id and an expiry, and its signature covers both, so every file of the version
 * shares one signed prefix and relative references inside a page keep working.
 * A link on the content domain also signs its sub-address label, so it fails on
 * any other address, and a link of the app domain fails on the content domain.
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
        private ArtifactContentHost $contentHost,
    ) {
    }

    public function sign(string $versionId, int $expires, string $label = ''): string
    {
        return hash_hmac('sha256', self::PURPOSE.'|'.$label.'|'.$versionId.'|'.$expires, $this->secret);
    }

    public function isValid(string $versionId, int $expires, string $signature, string $label = ''): bool
    {
        if ($expires < $this->clock->now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->sign($versionId, $expires, $label), $signature);
    }

    /**
     * The link of one file, valid for one hour. Absolute on the sub-address of
     * the artifact when the instance has a content domain, a path of the app
     * domain otherwise, unless the caller asks for an absolute URL. Encoded per
     * segment, so slashes stay.
     */
    public function linkFor(ArtifactVersion $version, string $path, int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        $versionId = (string) $version->id;
        $expires = $this->clock->now()->getTimestamp() + self::TIME_TO_LIVE;
        $label = null === $this->contentHost->host ? '' : $this->contentHost->labelFor($version->artifact);

        if ('' !== $label) {
            return $this->contentHost->originFor($label).'/'.implode('/', array_map(rawurlencode(...), [
                $versionId,
                (string) $expires,
                $this->sign($versionId, $expires, $label),
            ])).'/'.implode('/', array_map(rawurlencode(...), explode('/', $path)));
        }

        return $this->urls->generate(ShowArtifactContentController::ROUTE_SEALED, [
            'versionId' => $versionId,
            'expires' => $expires,
            'signature' => $this->sign($versionId, $expires),
            'path' => $path,
        ], $referenceType);
    }
}
