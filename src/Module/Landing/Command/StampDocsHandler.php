<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\ChromeCssExtractor;
use App\Module\Landing\Service\PublicPages;
use App\Module\Landing\Twig\LandingExtension;
use App\Site\ChromeLinkAudit;
use App\Site\SiteChrome;
use App\Site\SiteHost;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Twig\Environment;

/**
 * Copies the built docs to the folder that nginx serves, and puts the site bar
 * and footer of the running instance in place of the two markers of each page.
 */
final readonly class StampDocsHandler
{
    private const string NAV_TEMPLATE = '@Landing/landing/_nav.html.twig';

    private const string FOOTER_TEMPLATE = '@Landing/landing/_footer.html.twig';

    public function __construct(
        private Environment $twig,
        private SiteHost $siteHost,
        private PublicPages $publicPages,
        private LandingExtension $landing,
        private ChromeCssExtractor $cssExtractor,
        private Filesystem $filesystem,
        private LoggerInterface $logger,

        #[Autowire(param: 'app.url')]
        private string $appUrl,

        #[Autowire(param: 'app.docs_url')]
        private string $docsUrl,

        #[Autowire(param: 'app.source_url')]
        private string $sourceUrl,
    ) {
    }

    public function __invoke(StampDocsCommand $command): StampDocsResult
    {
        if (!is_dir($command->source)) {
            return new StampDocsResult(0, [sprintf('The built docs are missing: %s', $command->source)]);
        }
        $stylesheet = is_file($command->stylesheet) ? file_get_contents($command->stylesheet) : false;
        if (false === $stylesheet) {
            return new StampDocsResult(0, [sprintf('The compiled stylesheet is missing: %s', $command->stylesheet)]);
        }

        $nav = $this->twig->render(self::NAV_TEMPLATE);
        $footer = $this->twig->render(self::FOOTER_TEMPLATE);
        $problems = [
            ...ChromeLinkAudit::problems($nav, $this->configuredHosts(), !$this->siteHost->isSplit()),
            ...ChromeLinkAudit::problems($footer, $this->configuredHosts(), !$this->siteHost->isSplit()),
        ];
        if ([] !== $problems) {
            return new StampDocsResult(0, $problems);
        }

        $replacements = [
            SiteChrome::NAV_MARKER => '<style>'.$this->cssExtractor->extract($stylesheet, $nav).'</style>'.$nav,
            SiteChrome::FOOTER_MARKER => '<style>'.$this->cssExtractor->extract($stylesheet, $footer).'</style>'.$footer,
            SiteChrome::OG_IMAGE_PLACEHOLDER => $this->publicPages->absoluteUrl($this->openGraphImagePath()),
        ];

        $this->filesystem->mirror($command->source, $command->target, null, ['override' => true, 'delete' => true]);

        $pages = 0;
        foreach (new Finder()->files()->in($command->target)->name('*.html') as $file) {
            $path = $file->getPathname();
            $name = $file->getRelativePathname();
            $html = (string) file_get_contents($path);
            if (str_contains($html, 'data-pagefind-body')) {
                ++$pages;
                foreach ([SiteChrome::NAV_MARKER, SiteChrome::FOOTER_MARKER] as $marker) {
                    if (!str_contains($html, $marker)) {
                        $problems[] = sprintf('%s: the page has no %s', $name, $marker);
                    }
                }
            }
            $stamped = strtr($html, $replacements);
            if (str_contains($stamped, 'LOUPE_SITE_')) {
                $problems[] = sprintf('%s: a site chrome marker is left in the page', $name);
            }
            if ($stamped !== $html) {
                file_put_contents($path, $stamped);
            }
        }

        return new StampDocsResult($pages, $problems);
    }

    /** A database that is down must not fail a deploy, so the flag read falls back to the default image. */
    private function openGraphImagePath(): string
    {
        try {
            return $this->landing->openGraphImagePath();
        } catch (\Throwable $e) {
            $this->logger->warning('landing.docs_stamp_default_image', ['error' => $e->getMessage()]);

            return $this->landing->defaultOpenGraphImagePath();
        }
    }

    /** @return list<string> */
    private function configuredHosts(): array
    {
        $hosts = [$this->siteHost->host];
        foreach ([$this->appUrl, $this->docsUrl, $this->sourceUrl] as $url) {
            $hosts[] = parse_url($url, \PHP_URL_HOST) ?: null;
        }

        return array_values(array_filter($hosts, \is_string(...)));
    }
}
