<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Module\Artifact\Install\ArtifactInstallFlags;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Decides whether an app page may fetch a file of the sealed artifact route.
 * The browser says who asks and what for in the fetch metadata headers. A page
 * of the app passes only as a framed page, so an injected script tag cannot
 * load an uploaded file under the script rule of the app. A request with no
 * headers passes, because agents and old browsers send none.
 */
final readonly class ArtifactContentFetchGuard
{
    private const array NAVIGATIONS = ['document', 'iframe', 'frame'];
    private const array APP_SITES = ['same-origin', 'same-site'];

    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function refuses(Request $request): bool
    {
        if (!$this->featureFlags->isEnabled(ArtifactInstallFlags::FLAG_GUARD_SCRIPT_LOADS, ArtifactInstallFlags::DEFAULT_GUARD_SCRIPT_LOADS)) {
            return false;
        }

        $site = strtolower((string) $request->headers->get('Sec-Fetch-Site'));
        if (!\in_array($site, self::APP_SITES, true)) {
            return false;
        }

        return !\in_array(strtolower((string) $request->headers->get('Sec-Fetch-Dest')), self::NAVIGATIONS, true);
    }
}
