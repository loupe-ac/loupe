<?php

declare(strict_types=1);

namespace App\Module\Landing\Service;

use App\Module\Landing\ValueObject\FaqContent;
use App\Module\Landing\ValueObject\FaqQuestion;
use App\Site\SiteHost;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The FAQ of the public site, one Markdown file. */
final class FaqPage
{
    private ?FaqContent $content = null;

    private ?MarkdownConverter $converter = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/src/Module/Landing/Resources/faq.md')]
        private readonly string $file,
        private readonly SiteHost $siteHost,

        #[Autowire(param: 'app.url')]
        private readonly string $appUrl,
    ) {
    }

    public function content(): FaqContent
    {
        return $this->content ??= $this->load();
    }

    private function load(): FaqContent
    {
        if (!is_file($this->file)) {
            throw new \UnexpectedValueException('The FAQ file is missing.');
        }

        $this->converter ??= PageMarkdown::converter($this->siteHost, $this->appUrl);
        try {
            $rendered = $this->converter->convert((string) file_get_contents($this->file));
        } catch (\UnexpectedValueException $exception) {
            throw new \UnexpectedValueException('The FAQ file '.$exception->getMessage().'.', 0, $exception);
        }
        if (!$rendered instanceof RenderedContentWithFrontMatter) {
            throw new \UnexpectedValueException('The FAQ file has no front matter.');
        }
        $matter = $rendered->getFrontMatter();
        if (!\is_array($matter)) {
            throw new \UnexpectedValueException('The FAQ file has front matter that is not a map.');
        }

        $text = [];
        foreach (['title', 'description'] as $field) {
            $value = $matter[$field] ?? null;
            if (!\is_string($value) || '' === trim($value)) {
                throw new \UnexpectedValueException(sprintf('The FAQ file needs a non-empty text field %s.', $field));
            }
            $text[$field] = trim($value);
        }

        [$html, $questions] = $this->anchorQuestions($rendered->getContent());

        return new FaqContent($text['title'], $text['description'], $this->date($matter['updatedOn'] ?? null), $html, $questions);
    }

    /**
     * Gives each question heading an id, so a launch comment can link one answer.
     *
     * @return array{string, list<FaqQuestion>}
     */
    private function anchorQuestions(string $html): array
    {
        $questions = [];
        $html = preg_replace_callback('#<h2>(.*?)</h2>#s', static fn (array $match): string => '<h2 id="'.trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strip_tags($match[1]))), '-').'">'.$match[1].'</h2>', $html) ?? $html;
        $html = preg_replace_callback('#<h3>(.*?)</h3>#s', static function (array $match) use (&$questions): string {
            $anchor = 'q'.(\count($questions) + 1);
            $questions[] = new FaqQuestion(html_entity_decode(strip_tags($match[1]), \ENT_QUOTES | \ENT_HTML5), $anchor);

            return '<h3 id="'.$anchor.'">'.$match[1].'</h3>';
        }, $html) ?? $html;

        return [$html, $questions];
    }

    /** YAML reads an unquoted date as a Unix timestamp, so both shapes are accepted. */
    private function date(mixed $value): \DateTimeImmutable
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

        throw new \UnexpectedValueException('The FAQ file needs a date in the field updatedOn, written as YYYY-MM-DD.');
    }
}
