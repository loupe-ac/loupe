<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Service;

use App\Module\DesignSystem\ComponentEntry;
use Symfony\Component\Process\Process;

/**
 * Builds `components/bundle.js` of the Design System artifact. Each component
 * source is compiled by esbuild and wrapped in its own scope, so one failing
 * component never stops the others. The bundle puts every export on the
 * global `LoupeDesignSystem_686f56`, the name that every canvas mounts.
 */
final readonly class ComponentBundler
{
    public const string NAMESPACE = 'LoupeDesignSystem_686f56';

    public function __construct(
        private ComponentSource $source,
    ) {
    }

    /** @param list<ComponentEntry> $entries */
    public function bundle(array $entries): string
    {
        $blocks = [];
        $exports = [];
        $components = [];
        $hashes = [];
        foreach ($entries as $entry) {
            $source = $this->source->jsx($entry);
            $path = sprintf('components/%1$s/%2$s/%2$s.jsx', $entry->group, $entry->name);
            $components[] = ['name' => $entry->name, 'sourcePath' => 'components/src/'.substr($path, \strlen('components/'))];
            $hashes[$path] = substr(hash('sha256', $source), 0, 12);

            $names = $this->exportedNames($source);
            $exports = [...$exports, ...$names];
            $blocks[] = sprintf(
                "// %s\ntry { (() => {\n%s\nObject.assign(__ds_scope, { %s });\n})(); } catch (e) { __ds_ns.__errors.push({ path: %s, error: String((e && e.message) || e) }); }\n",
                $path,
                rtrim($this->compile($source)),
                implode(', ', $names),
                json_encode($path, \JSON_THROW_ON_ERROR),
            );
        }

        $header = json_encode([
            'format' => 4,
            'namespace' => self::NAMESPACE,
            'components' => $components,
            'sourceHashes' => $hashes,
            'inlinedExternals' => [],
            'unexposedExports' => [],
        ], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        $assignments = array_map(static fn (string $name): string => sprintf('__ds_ns.%1$s = __ds_scope.%1$s;', $name), $exports);

        return sprintf(
            "/* @ds-bundle: %s */\n(() => {\n\nconst __ds_ns = (window.%s = window.%s || {});\n\nconst __ds_scope = {};\n\n(__ds_ns.__errors = __ds_ns.__errors || []);\n\n%s\n%s\n\n})();\n",
            $header,
            self::NAMESPACE,
            self::NAMESPACE,
            implode("\n", $blocks),
            implode("\n\n", $assignments),
        );
    }

    /** @return list<string> */
    private function exportedNames(string $source): array
    {
        preg_match_all('~^export (?:function|const) (\w+)~m', $source, $matches);

        return $matches[1];
    }

    private function compile(string $source): string
    {
        $plain = preg_replace(['~^import [^\n]*\n~m', '~^export ~m'], '', $source) ?? $source;
        $process = new Process(['esbuild', '--loader=jsx', '--jsx=transform', '--target=es2020'], null, null, $plain, 30);
        $process->mustRun();

        return $process->getOutput();
    }
}
