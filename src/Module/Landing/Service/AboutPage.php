<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use App\Module\Landing\ValueObject\SitePage;
use App\Site\SiteHost;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The about page of the public site, one Markdown file. */
final class AboutPage
{
    private ?SitePage $page = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Module/Landing/Resources/about.md')]
        private readonly string $file,
        private readonly SiteHost $siteHost,

        #[Autowire(param: 'app.url')]
        private readonly string $appUrl,
    ) {
    }

    public function page(): SitePage
    {
        return $this->page ??= $this->load();
    }

    private function load(): SitePage
    {
        if (!is_file($this->file)) {
            throw new \UnexpectedValueException('The about file is missing.');
        }

        try {
            $rendered = PageMarkdown::converter($this->siteHost, $this->appUrl)->convert((string) file_get_contents($this->file));
        } catch (\UnexpectedValueException $exception) {
            throw new \UnexpectedValueException('The about file '.$exception->getMessage().'.', 0, $exception);
        }
        if (!$rendered instanceof RenderedContentWithFrontMatter) {
            throw new \UnexpectedValueException('The about file has no front matter.');
        }
        $matter = $rendered->getFrontMatter();
        if (!\is_array($matter)) {
            throw new \UnexpectedValueException('The about file has front matter that is not a map.');
        }

        $text = [];
        foreach (['title', 'description'] as $field) {
            $value = $matter[$field] ?? null;
            if (!\is_string($value) || '' === trim($value)) {
                throw new \UnexpectedValueException(sprintf('The about file needs a non-empty text field %s.', $field));
            }
            $text[$field] = trim($value);
        }

        return new SitePage($text['title'], $text['description'], $rendered->getContent());
    }
}
