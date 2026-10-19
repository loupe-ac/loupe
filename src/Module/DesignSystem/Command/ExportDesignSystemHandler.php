<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Command;

use App\Module\DesignSystem\Catalog;
use App\Module\DesignSystem\Service\ComponentBundler;
use App\Module\DesignSystem\Service\ComponentSource;
use App\Module\DesignSystem\Service\TailwindCompiler;
use App\Module\DesignSystem\Token\ArtifactTokens;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

/**
 * Writes the file layout of the Design System artifact. The artifact keeps
 * `ds-mount.js` and the bookkeeping keys of `design-system.json` itself, so
 * this handler never writes them.
 */
final readonly class ExportDesignSystemHandler
{
    private const array PLATFORM_FILES = [
        'ds-mount.js',
        'tokens.css',
    ];

    /** Custom properties that the artifact must not read as theme tokens. */
    private const string OTHER_KIND = '~(?<![\w-])(--(?:tw-|duration-|ease-|default-transition-|animate-)[\w-]*:[^;{}]*)(;|(?=\}))~';

    public function __construct(
        private Catalog $catalog,
        private ArtifactTokens $tokens,
        private TailwindCompiler $tailwind,
        private ComponentSource $source,
        private ComponentBundler $bundler,
        private Environment $twig,

        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /** @return list<string> the paths written, relative to the directory */
    public function __invoke(ExportDesignSystemCommand $command): array
    {
        $root = rtrim($command->directory, '/');
        $filesystem = new Filesystem();
        $written = [];
        $write = function (string $path, string $content) use ($root, $filesystem, &$written): void {
            if (\in_array(basename($path), self::PLATFORM_FILES, true)) {
                throw new \LogicException(sprintf('%s belongs to the platform.', $path));
            }

            $filesystem->dumpFile($root.'/'.$path, $content);
            $written[] = $path;
        };

        $entries = $this->catalog->entries();
        $names = array_map(static fn ($entry): string => $entry->name, $entries);

        $write('design-system.json', $this->json([
            'v' => 3,
            'docs' => ['readme' => 'project/README.md'],
            'layout' => 'files',
            'libraries' => [['name' => 'react', 'version' => '18'], ['name' => 'react-dom', 'version' => '18']],
            'namespace' => ComponentBundler::NAMESPACE,
            'title' => 'Loupe Design System',
        ]));
        $write('tokens.json', $this->json($this->tokens->build()));

        $docs = $this->read($this->projectDir.'/docs/contributing/design-system.md');
        $write('README.md', $this->render('readme.md.twig', [
            'docs' => ltrim(preg_replace('/\A---\n.*?\n---\n/s', '', $docs) ?? $docs),
        ]));

        $types = [];
        foreach ($entries as $entry) {
            $base = sprintf('components/%s/', $entry->name);
            $declarations = $this->source->types($entry);
            $write($base.'README.md', $this->source->readme($entry));
            $write($base.$entry->name.'.d.ts', $declarations);
            $write($base.'preview.html', $this->source->preview($entry, $names));
            $types[] = '// '.$entry->name."\n".trim(preg_replace('~^import type [^\n]*\n~m', '', $declarations) ?? $declarations);
        }

        $write('components/index.d.ts', "import type { ReactNode } from 'react';\n\n".implode("\n\n", $types)."\n");
        $write('components/bundle.js', $this->bundler->bundle($entries));
        $write('components/bundle.css', $this->bundleCss());

        foreach (glob($this->projectDir.'/assets/fonts/*.{woff2,txt}', \GLOB_BRACE) ?: [] as $font) {
            $write('fonts/'.basename($font), $this->read($font));
        }

        sort($written);

        return $written;
    }

    private function bundleCss(): string
    {
        $header = "/* Loupe — component and utility styles (the Tailwind build of assets/styles/design-system.css).\n   Load after tokens.css, which declares the colours, fonts, spacing, radii and shadows. */\n\n";

        return $header.trim($this->render('base.css.twig'))."\n\n".$this->markOtherKind($this->tokens->rootCss()."\n".$this->tailwind->compile($this->projectDir.'/assets/styles/design-system.css'));
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context = []): string
    {
        return $this->twig->render('@DesignSystem/export/'.$template, $context);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Cannot read %s.', $path));
        }

        return $content;
    }

    /** Tags each Tailwind internal, motion and transition property as `@kind other`. */
    private function markOtherKind(string $css): string
    {
        return preg_replace(self::OTHER_KIND, '$1; /* @kind other */', $css) ?? $css;
    }
}
