<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\PublicPages;

final readonly class ShowSitemapHandler
{
    public function __construct(
        private PublicPages $publicPages,
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
        foreach ($this->publicPages->sitemapUrls() as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url);
            $xml->endElement();
        }
        $xml->endElement();

        return $xml->outputMemory();
    }
}
