<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use App\Module\Landing\ValueObject\ComparisonPage;
use App\Site\SiteHost;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/** The comparison pages of the public site, one Markdown file each. */
final class ComparisonPages
{
    public const string SLUG_PATTERN = '[a-z0-9-]+';

    private const array TEXT_FIELDS = ['competitor', 'title', 'description', 'competitorVersion'];

    /** @var list<ComparisonPage>|null */
    private ?array $pages = null;

    private ?MarkdownConverter $converter = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Module/Landing/Resources/compare')]
        private readonly string $directory,
        private readonly SiteHost $siteHost,

        #[Autowire(param: 'app.url')]
        private readonly string $appUrl,
    ) {
    }

    /** @return list<ComparisonPage> every page, sorted by slug */
    public function all(): array
    {
        return $this->pages ??= $this->load();
    }

    public function find(string $slug): ?ComparisonPage
    {
        foreach ($this->all() as $page) {
            if ($page->slug === $slug) {
                return $page;
            }
        }

        return null;
    }

    /** @return list<ComparisonPage> */
    private function load(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $pages = [];
        foreach (new Finder()->files()->in($this->directory)->depth(0)->name('*.md')->sortByName() as $file) {
            $pages[] = $this->parse($file->getBasename('.md'), $file->getContents(), $file->getFilename());
        }
        usort($pages, static fn (ComparisonPage $a, ComparisonPage $b): int => strcmp($a->slug, $b->slug));

        return $pages;
    }

    private function parse(string $slug, string $markdown, string $filename): ComparisonPage
    {
        if (1 !== preg_match('/^'.self::SLUG_PATTERN.'$/', $slug)) {
            throw new \UnexpectedValueException(sprintf('The comparison file %s must have a name that matches %s.', $filename, self::SLUG_PATTERN));
        }

        try {
            $rendered = $this->converter()->convert($markdown);
        } catch (\UnexpectedValueException $exception) {
            throw new \UnexpectedValueException(sprintf('The comparison file %s %s.', $filename, $exception->getMessage()), 0, $exception);
        }
        if (!$rendered instanceof RenderedContentWithFrontMatter) {
            throw new \UnexpectedValueException(sprintf('The comparison file %s has no front matter.', $filename));
        }
        $matter = $rendered->getFrontMatter();
        if (!\is_array($matter)) {
            throw new \UnexpectedValueException(sprintf('The comparison file %s has front matter that is not a map.', $filename));
        }

        $text = [];
        foreach (self::TEXT_FIELDS as $field) {
            $value = $matter[$field] ?? null;
            if (!\is_string($value) || '' === trim($value)) {
                throw new \UnexpectedValueException(sprintf('The comparison file %s needs a non-empty text field %s.', $filename, $field));
            }
            $text[$field] = trim($value);
        }

        return new ComparisonPage(
            $slug,
            $text['competitor'],
            $text['title'],
            $text['description'],
            $this->date($matter['comparedOn'] ?? null, $filename),
            $text['competitorVersion'],
            $rendered->getContent(),
        );
    }

    /** YAML reads an unquoted date as a Unix timestamp, so both shapes are accepted. */
    private function date(mixed $value, string $filename): \DateTimeImmutable
    {
        if (\is_int($value)) {
            return new \DateTimeImmutable('@'.$value);
        }
        if (\is_string($value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            if (false !== $date && $date->format('Y-m-d') === $value) {
                return $date;
            }
        }

        throw new \UnexpectedValueException(sprintf('The comparison file %s needs a date in the field comparedOn, written as YYYY-MM-DD.', $filename));
    }

    private function converter(): MarkdownConverter
    {
        if (null === $this->converter) {
            $environment = new Environment([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                'external_link' => [
                    'internal_hosts' => array_values(array_filter([
                        $this->siteHost->host,
                        parse_url($this->appUrl, \PHP_URL_HOST) ?: null,
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
            $this->converter = new MarkdownConverter($environment);
        }

        return $this->converter;
    }
}
