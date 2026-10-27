<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use App\Site\SiteHost;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/** Builds the Markdown converter that every content page of the public site uses. */
final class PageMarkdown
{
    public static function converter(SiteHost $siteHost, string $appUrl): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'external_link' => [
                'internal_hosts' => array_values(array_filter([
                    $siteHost->host,
                    parse_url($appUrl, \PHP_URL_HOST) ?: null,
                ])),
                'open_in_new_window' => false,
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new FrontMatterExtension());
        $environment->addExtension(new ExternalLinkExtension());
        $environment->addEventListener(DocumentParsedEvent::class, new SourceCitations());

        return new MarkdownConverter($environment);
    }
}
