<?php

declare(strict_types=1);

namespace App\DesignStarter;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfonycasts\TailwindBundle\TailwindBuilder;
use Twig\Environment;

/**
 * Writes the Claude Design starter canvas: the full stylesheet, the fonts and
 * six page shells that each hold one empty slot.
 *
 * It reads the Tailwind builder and Twig directly. The design system module
 * is a leaf, so this class imports nothing from it.
 */
final readonly class StarterExporter
{
    public const int PART_LIMIT = 200_000;

    private const string SLOT = '[data-starter-slot]';

    /** @var array<string, array{template: string, route: string}> */
    private const array SHELLS = [
        'App frame.dc.html' => ['template' => 'app_frame', 'route' => 'app_project_board'],
        'Document review.dc.html' => ['template' => 'document_review', 'route' => 'app_document_review'],
        'Board.dc.html' => ['template' => 'board', 'route' => 'app_project_board'],
        'Card page.dc.html' => ['template' => 'card_page', 'route' => 'app_board_card'],
        'Inbox.dc.html' => ['template' => 'inbox', 'route' => 'app_project_inbox'],
        'Settings.dc.html' => ['template' => 'settings', 'route' => 'app_project_edit'],
    ];

    public function __construct(
        #[Autowire(service: '.tailwind.builder')]
        private TailwindBuilder $builder,
        private Environment $twig,
        private StylesheetSplitter $splitter,

        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /** @return list<string> the paths written, relative to the directory and sorted */
    public function __invoke(string $directory): array
    {
        $root = rtrim($directory, '/');
        $filesystem = new Filesystem();

        /** @var array<string, string> $files */
        $files = [];
        $parts = $this->splitter->split($this->stylesheet(), self::PART_LIMIT);
        $imports = '';
        foreach ($parts as $index => $part) {
            $name = sprintf('app-%d.css', $index + 1);
            $files['styles/'.$name] = $part;
            $imports .= sprintf("@import \"%s\";\n", $name);
        }
        $files['styles/app.css'] = $imports;

        foreach (self::SHELLS as $name => $shell) {
            $files[$name] = $this->shell($name, $shell['template']);
        }

        foreach (glob($this->projectDir.'/assets/fonts/*.{woff2,txt}', \GLOB_BRACE) ?: [] as $font) {
            $files['assets/fonts/'.basename($font)] = $this->read($font);
        }

        ksort($files);
        $manifest = ['starter' => 1, 'files' => [], 'shells' => []];
        foreach ($files as $path => $content) {
            $manifest['files'][$path] = 'sha256:'.hash('sha256', $content);
        }
        foreach (self::SHELLS as $name => $shell) {
            $manifest['shells'][$name] = ['route' => $shell['route'], 'slot' => self::SLOT];
        }
        $files['starter.json'] = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";

        foreach ($files as $path => $content) {
            $filesystem->dumpFile($root.'/'.$path, $content);
        }

        $written = array_keys($files);
        sort($written);

        return $written;
    }

    private function shell(string $name, string $template): string
    {
        $html = $this->twig->render('@DesignSystem/export/starter/'.$template.'.html.twig');

        if (str_contains($html, '{{')) {
            throw new \RuntimeException(sprintf('%s holds "{{", which the canvas reads as a template hole.', $name));
        }
        if (1 === preg_match('~<script|<[^>]*\s(?:hidden|open)(?=[\s=/>])~i', $html)) {
            throw new \RuntimeException(sprintf('%s holds a script, a hidden attribute or an open attribute.', $name));
        }
        if (1 !== preg_match_all('~<(\w+)\s[^>]*data-starter-slot[^>]*></\1>~', $html) || 1 !== substr_count($html, 'data-starter-slot')) {
            throw new \RuntimeException(sprintf('%s must hold exactly one empty element with data-starter-slot.', $name));
        }

        return $html;
    }

    private function stylesheet(): string
    {
        $output = tempnam(sys_get_temp_dir(), 'starter-tailwind');
        if (false === $output) {
            throw new \RuntimeException('Cannot create a temporary file for the Tailwind build.');
        }

        try {
            $process = $this->builder->createBinary()->createProcess(['-i', $this->projectDir.'/assets/styles/app.css', '-o', $output, '--minify']);
            $process->setTimeout(120);
            $process->mustRun();
            $css = $this->read($output);
        } finally {
            unlink($output);
        }

        if ('' === $css) {
            throw new \RuntimeException('Tailwind built nothing from assets/styles/app.css.');
        }

        return str_replace('../fonts/', '../assets/fonts/', $css);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Cannot read %s.', $path));
        }

        return $content;
    }
}
