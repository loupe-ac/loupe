<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Token;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds `tokens.json` of the Design System artifact from the token
 * stylesheet. The colour list holds the raw values first, then the Tailwind
 * names that point at them as `{token}` references.
 */
final readonly class ArtifactTokens
{
    private const array TEXT_SCALE = [
        'xs' => ['0.75rem', '1rem', ''],
        'sm' => ['0.875rem', '1.25rem', ' Also the body size the base resets set on `body`.'],
        'base' => ['1rem', '1.5rem', ''],
        'lg' => ['1.125rem', '1.75rem', ''],
        'xl' => ['1.25rem', '1.75rem', ''],
        '2xl' => ['1.5rem', '2rem', ''],
        '3xl' => ['1.875rem', '2.25rem', ''],
    ];

    /** Shadows that name a colour token stay in the stylesheet, because `tokens.json` holds plain values. */
    private const string SHADOW_NOTE = 'Shadows are reserved for surfaces that float above the page. `shadow-comic` (6px 6px 0 `accent`, the landing page\'s hard-offset balloon) and `shadow-focus` (a 3px `accent-faint` halo round a selected surface) are built from colour tokens, so they stay in components/bundle.css.';

    public function __construct(
        private TokenReader $reader,

        #[Autowire('%kernel.project_dir%/assets/styles/tokens.css')]
        private string $tokenFile,

        #[Autowire('%kernel.project_dir%/assets/styles/app.css')]
        private string $appFile,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $groups = [];
        foreach ($this->reader->groups() as $group) {
            $groups[$group->name] = $group->tokens;
        }

        $colour = array_map(static fn (Token $token): array => [
            'name' => substr($token->name, 2),
            'value' => $token->value,
            'usage' => $token->use,
        ], $groups['colour'] ?? []);

        return [
            'name' => 'Loupe Design System',
            'version' => 1,
            'color' => [
                'themes' => [['id' => 'light', 'name' => 'Light']],
                'tokens' => [...$colour, ...$this->tailwindNames()],
            ],
            'type' => [
                'fonts' => $this->fonts(),
                'families' => $this->families($groups['type'] ?? []),
                'groups' => [['name' => 'Text scale', 'family' => 'sans', 'styles' => $this->textScale()]],
            ],
            'spacing' => ['tokens' => $this->plain($groups['spacing'] ?? [])],
            'radius' => ['tokens' => $this->plain($groups['radius'] ?? [])],
            'shadow' => [
                'note' => self::SHADOW_NOTE,
                'tokens' => $this->plain(array_values(array_filter(
                    $groups['shadow'] ?? [],
                    static fn (Token $token): bool => !str_contains($token->value, 'var('),
                ))),
            ],
        ];
    }

    /**
     * The custom properties that `tokens.json` cannot hold: the shadows built
     * from colour tokens, and the motion tokens. They ship in `bundle.css`.
     */
    public function rootCss(): string
    {
        $groups = [];
        foreach ($this->reader->groups() as $group) {
            $groups[$group->name] = $group->tokens;
        }

        $shadows = array_filter($groups['shadow'] ?? [], static fn (Token $token): bool => str_contains($token->value, 'var('));
        $blocks = [];
        if ([] !== $shadows) {
            $lines = array_map(static fn (Token $token): string => sprintf('    %s: %s;', $token->name, $token->value), $shadows);
            $blocks[] = ":root {\n    /* The two shadows built from colour tokens. `--shadow-focus` is the halo for a\n       selected surface. The comic balloon is a hard offset rather than a blur and\n       belongs to the landing page alone. */\n".implode("\n", $lines)."\n}\n";
        }

        $motion = [];
        $previous = '';
        foreach ($groups['motion'] ?? [] as $token) {
            if ('' !== $token->use && $token->use !== $previous) {
                $motion[] = '    /* '.$token->use.' */';
            }

            $previous = $token->use;

            $motion[] = sprintf('    %s: %s;', $token->name, $token->value);
        }

        if ([] !== $motion) {
            $blocks[] = ":root {\n".implode("\n", $motion)."\n}\n";
        }

        return implode("\n", $blocks);
    }

    /**
     * @param list<Token> $tokens
     *
     * @return list<array{name: string, value: string, usage: string}>
     */
    private function plain(array $tokens): array
    {
        return array_map(static fn (Token $token): array => [
            'name' => substr($token->name, 2),
            'value' => $token->value,
            'usage' => $token->use,
        ], $tokens);
    }

    /** @return list<array{name: string, value: string, usage: string}> */
    private function tailwindNames(): array
    {
        preg_match_all('~^\s*--(color-([\w-]+)):\s*var\(--([\w-]+)\);~m', $this->read($this->tokenFile), $matches, \PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [
            'name' => $match[1],
            'value' => '{'.$match[3].'}',
            'usage' => sprintf(
                'The Tailwind name of `%1$s`: utilities such as `bg-%2$s`, `text-%2$s` and `border-%2$s` read it. Set the value on `%1$s`.',
                $match[3],
                $match[2],
            ),
        ], $matches);
    }

    /** @return list<array{family: string, file: string, weight: string, style: string}> */
    private function fonts(): array
    {
        preg_match_all('~@font-face\s*\{(.*?)\}~s', $this->read($this->appFile), $faces);
        $fonts = [];
        foreach ($faces[1] as $face) {
            if (1 !== preg_match("~font-family:\s*'([^']+)'~", $face, $family)
                || 1 !== preg_match("~url\('\.\./(fonts/[^']+)'\)~", $face, $file)
                || 1 !== preg_match('~font-weight:\s*([^;]+);~', $face, $weight)
                || 1 !== preg_match('~font-style:\s*([^;]+);~', $face, $style)) {
                continue;
            }

            $fonts[] = ['family' => $family[1], 'file' => $file[1], 'weight' => trim($weight[1]), 'style' => trim($style[1])];
        }

        return $fonts;
    }

    /**
     * @param list<Token> $tokens
     *
     * @return array<string, string>
     */
    private function families(array $tokens): array
    {
        $families = [];
        foreach ($tokens as $token) {
            if (str_starts_with($token->name, '--font-')) {
                $families[substr($token->name, \strlen('--font-'))] = $token->value;
            }
        }

        return $families;
    }

    /** @return list<array<string, mixed>> */
    private function textScale(): array
    {
        $styles = [];
        foreach (self::TEXT_SCALE as $step => [$size, $line, $extra]) {
            $styles[] = [
                'name' => 'type-'.$step,
                'fontSize' => $size,
                'lineHeight' => $line,
                'fontWeight' => 400,
                'usage' => sprintf('The Tailwind `text-%1$s` step: write `text-%1$s` in markup.%2$s', $step, $extra),
            ];
        }

        return $styles;
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
