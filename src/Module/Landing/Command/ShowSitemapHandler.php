<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\PublicPages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ShowSitemapHandler
{
    public function __construct(
        private PublicPages $publicPages,

        #[Autowire(param: 'app.site.docs_dir')]
        private string $docsDir,
    ) {
    }

    /** @return string the XML body of sitemap.xml */
    public function __invoke(): string
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ([...$this->publicPages->sitemapUrls(), ...$this->docsUrls()] as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url);
            $xml->endElement();
        }
        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * The docs pages, as the Starlight sitemap lists them, on the site origin of
     * this instance. A missing or broken sitemap lists none.
     *
     * @return list<string>
     */
    private function docsUrls(): array
    {
        $urls = [];
        foreach ($this->locations($this->docsDir.'/sitemap-index.xml') as $sitemap) {
            $name = basename((string) parse_url($sitemap, \PHP_URL_PATH));
            if ('' === $name || !str_ends_with($name, '.xml')) {
                continue;
            }
            foreach ($this->locations($this->docsDir.'/'.$name) as $page) {
                $path = parse_url($page, \PHP_URL_PATH);
                if (\is_string($path) && str_starts_with($path, '/')) {
                    $urls[$path] = $this->publicPages->absoluteUrl($path);
                }
            }
        }

        return array_values($urls);
    }

    /** @return list<string> the loc elements of a sitemap file, or none when it cannot be read */
    private function locations(string $file): array
    {
        $content = is_file($file) ? file_get_contents($file) : false;
        if (false === $content) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, options: \LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if (false === $xml) {
            return [];
        }

        return array_values(array_map(strval(...), $xml->xpath('//*[local-name()="loc"]') ?: []));
    }
}
