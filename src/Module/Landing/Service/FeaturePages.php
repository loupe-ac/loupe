<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use App\Module\Landing\ValueObject\FeatureBlock;
use App\Module\Landing\ValueObject\FeaturePage;
use App\Site\SiteHost;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/** The eight feature pages of the public site, in carousel order. */
final class FeaturePages
{
    /** The slugs of {@see self::ENTRIES}, as a route requirement. */
    public const string SLUG_PATTERN = 'board|workflow-rules|interactive-sessions|document-review|inbox|agent-review|live-review|analytics';

    private const array ENTRIES = [
        ['key' => 'board', 'slug' => 'board', 'icon' => 'lucide:square-kanban', 'docsPath' => '/docs/using/board/'],
        ['key' => 'workflow_rules', 'slug' => 'workflow-rules', 'icon' => 'lucide:workflow', 'docsPath' => '/docs/using/workflows/'],
        ['key' => 'sessions', 'slug' => 'interactive-sessions', 'icon' => 'lucide:terminal', 'docsPath' => '/docs/using/interactive-sessions/'],
        ['key' => 'document_review', 'slug' => 'document-review', 'icon' => 'lucide:file-text', 'docsPath' => '/docs/using/documents/'],
        ['key' => 'inbox', 'slug' => 'inbox', 'icon' => 'lucide:inbox', 'docsPath' => '/docs/using/inbox/'],
        ['key' => 'agent_review', 'slug' => 'agent-review', 'icon' => 'lucide:git-pull-request', 'docsPath' => '/docs/using/agent-review/'],
        ['key' => 'site_review', 'slug' => 'live-review', 'icon' => 'lucide:message-square-plus', 'docsPath' => '/docs/using/live-review/'],
        ['key' => 'analytics', 'slug' => 'analytics', 'icon' => 'lucide:chart-column', 'docsPath' => '/docs/using/analytics/'],
    ];

    private const array MOCKUPS = ['hero', 'block-1', 'block-2', 'block-3'];

    private const array TEXT_FIELDS = ['label', 'title', 'lead', 'description'];

    private const int BLOCK_COUNT = 3;

    /** @var list<FeaturePage>|null */
    private ?array $pages = null;

    private ?MarkdownConverter $converter = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Module/Landing/Resources/features')]
        private readonly string $directory,
        private readonly SiteHost $siteHost,

        #[Autowire(param: 'app.url')]
        private readonly string $appUrl,
    ) {
    }

    /** @return list<FeaturePage> */
    public function all(): array
    {
        return $this->pages ??= $this->load();
    }

    public function find(string $slug): ?FeaturePage
    {
        foreach ($this->all() as $page) {
            if ($page->slug === $slug) {
                return $page;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function slugs(): array
    {
        return array_column(self::ENTRIES, 'slug');
    }

    /** @return list<FeaturePage> */
    private function load(): array
    {
        $known = array_map(static fn (string $slug): string => $slug.'.md', $this->slugs());
        if (is_dir($this->directory)) {
            foreach (new Finder()->files()->in($this->directory)->depth(0)->name('*.md') as $file) {
                if (!\in_array($file->getFilename(), $known, true)) {
                    throw new \UnexpectedValueException(sprintf('The feature file %s matches no feature slug.', $file->getFilename()));
                }
            }
        }

        $pages = [];
        foreach (self::ENTRIES as $entry) {
            $pages[] = $this->parse($entry);
        }

        return $pages;
    }

    /** @param array{key: string, slug: string, icon: string, docsPath: string} $entry */
    private function parse(array $entry): FeaturePage
    {
        $filename = $entry['slug'].'.md';
        $path = $this->directory.'/'.$filename;
        if (!is_file($path)) {
            throw new \UnexpectedValueException(sprintf('The feature file %s is missing.', $filename));
        }

        $rendered = $this->converter()->convert((string) file_get_contents($path));
        if (!$rendered instanceof RenderedContentWithFrontMatter) {
            throw new \UnexpectedValueException(sprintf('The feature file %s has no front matter.', $filename));
        }
        $matter = $rendered->getFrontMatter();
        if (!\is_array($matter)) {
            throw new \UnexpectedValueException(sprintf('The feature file %s has front matter that is not a map.', $filename));
        }

        $text = [];
        foreach (self::TEXT_FIELDS as $field) {
            $text[$field] = $this->text($matter[$field] ?? null, $field, $filename);
        }

        return new FeaturePage(
            $entry['key'],
            $entry['slug'],
            $entry['icon'],
            $entry['docsPath'],
            array_map(static fn (string $name): string => sprintf('@Landing/features/mockups/%s/%s.html.twig', $entry['slug'], $name), self::MOCKUPS),
            $text['label'],
            $text['title'],
            $text['lead'],
            $text['description'],
            $this->blocks($matter['blocks'] ?? null, $filename),
        );
    }

    /** @return list<FeatureBlock> */
    private function blocks(mixed $value, string $filename): array
    {
        if (!\is_array($value) || self::BLOCK_COUNT !== \count($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException(sprintf('The feature file %s needs a list of %d blocks in the field blocks.', $filename, self::BLOCK_COUNT));
        }

        $blocks = [];
        foreach ($value as $index => $block) {
            $fields = \is_array($block) ? $block : [];
            $blocks[] = new FeatureBlock(
                $this->text($fields['title'] ?? null, sprintf('blocks[%d].title', $index), $filename),
                $this->text($fields['text'] ?? null, sprintf('blocks[%d].text', $index), $filename),
            );
        }

        return $blocks;
    }

    private function text(mixed $value, string $field, string $filename): string
    {
        if (!\is_string($value) || '' === trim($value)) {
            throw new \UnexpectedValueException(sprintf('The feature file %s needs a non-empty text field %s.', $filename, $field));
        }

        return trim($value);
    }

    private function converter(): MarkdownConverter
    {
        return $this->converter ??= PageMarkdown::converter($this->siteHost, $this->appUrl);
    }
}
