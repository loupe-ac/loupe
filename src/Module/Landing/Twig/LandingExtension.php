<?php

declare(strict_types=1);

namespace App\Module\Landing\Twig;

use App\Module\Landing\Service\PublicPages;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class LandingExtension extends AbstractExtension
{
    public const string OPEN_GRAPH_IMAGE_FLAG = 'open_graph.image';

    public const string DEFAULT_OPEN_GRAPH_IMAGE = 'hero';

    /** @var list<string> */
    public const array OPEN_GRAPH_IMAGES = ['hero', 'board'];

    public function __construct(
        private readonly PublicPages $publicPages,
        private readonly FeatureFlagService $featureFlags,
        private readonly Packages $packages,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_url', $this->publicPages->absoluteUrl(...)),
            new TwigFunction('og_image_path', $this->openGraphImagePath(...)),
            new TwigFunction('is_public_page', $this->isPublicPage(...)),
            new TwigFunction('public_page_paths', $this->publicPages->paths(...)),
        ];
    }

    public function isPublicPage(): bool
    {
        $route = $this->requestStack->getMainRequest()?->attributes->get('_route');

        return $this->publicPages->isPublic(\is_string($route) ? $route : null);
    }

    /** A missing row or an unknown value falls back to the default image. */
    public function openGraphImagePath(): string
    {
        $image = $this->featureFlags->getValue(self::OPEN_GRAPH_IMAGE_FLAG);
        if (!\is_string($image) || !\in_array($image, self::OPEN_GRAPH_IMAGES, true)) {
            $image = self::DEFAULT_OPEN_GRAPH_IMAGE;
        }

        return $this->packages->getUrl('images/og/'.$image.'.png');
    }
}
