<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use App\Module\Landing\Service\ComparisonPages;
use App\Module\Landing\Service\PublicPages;
use App\Routing\PinnedUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ShowLlmsHandler
{
    public function __construct(
        private PinnedUrlGenerator $appUrls,
        private TranslatorInterface $translator,
        private PublicPages $publicPages,
        private ComparisonPages $comparisonPages,

        #[Autowire(param: 'app.docs_url')]
        private string $docsUrl,
    ) {
    }

    /** @return string the body of llms.txt, in the Markdown shape that llmstxt.org defines */
    public function __invoke(): string
    {
        $lines = [
            '# '.$this->translator->trans('app.name'),
            '',
            '> '.$this->translator->trans('landing.meta.description'),
            '',
            '## Docs',
            '',
            $this->link('landing.llms.setup_label', $this->appUrls->generate('app_setup_instructions'), 'landing.llms.setup_note'),
            $this->link('landing.llms.docs_label', rtrim($this->docsUrl, '/').'/', 'landing.llms.docs_note'),
        ];
        if ($this->publicPages->hasComparisons()) {
            $lines[] = '';
            $lines[] = '## Compare';
            $lines[] = '';
            foreach ($this->comparisonPages->all() as $page) {
                $lines[] = '- ['.$page->title.']('.$this->publicPages->comparisonUrl($page).'): '.$page->description;
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function link(string $label, string $url, string $note): string
    {
        return '- ['.$this->translator->trans($label).']('.$url.'): '.$this->translator->trans($note);
    }
}
