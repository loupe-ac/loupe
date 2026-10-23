import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    KEPT_CSS_PROPERTIES,
    KEPT_CSS_WORDS,
    scrubCss,
} from '../../assets/lib/sentry_scrub.js';

const built = (css) => scrubCss(css, { builtStylesheet: true });

describe('scrubCss in inline CSS', () => {
    it('keeps a listed layout property', () => {
        expect(scrubCss('top: 120px; left: 8px')).toBe(
            'top: 120px; left: 8px;',
        );
    });

    it('keeps transforms, calc and a colour', () => {
        expect(
            scrubCss(
                'transform: translateX(-50%); width: calc(var(--x) * 12); background: #eee',
            ),
        ).toBe(
            'transform: translateX(-50%); width: calc(var(--x) * 12); background: #eee;',
        );
    });

    it('removes a comment', () => {
        const css = scrubCss('top: 1px; /* private note */ left: 2px');
        expect(css).not.toContain('private');
        expect(css).toBe('top: 1px; left: 2px;');
    });

    it('removes a custom property, also a numeric one', () => {
        expect(scrubCss('--account-id: 987654321; top: 1px')).toBe('top: 1px;');
        expect(scrubCss('--private-note: private note')).toBe('');
    });

    it('removes a property outside the layout list, also a numeric one', () => {
        expect(scrubCss('tab-size: 987654321; top: 1px')).toBe('top: 1px;');
        expect(scrubCss('font-family: private note')).toBe('');
        expect(scrubCss('content: "private note"')).toBe('');
    });

    it('removes a listed property that holds an unknown word', () => {
        expect(scrubCss('position: privateword; top: 1px')).toBe('top: 1px;');
    });

    it('empties a quoted string inside a kept property', () => {
        expect(scrubCss("font-family: 'Geist', sans-serif")).toBe(
            'font-family: "", sans-serif;',
        );
    });

    it('cuts a url to its origin and drops a data url', () => {
        expect(scrubCss('background: url(https://cdn.test/a/b.png?x=1)')).toBe(
            'background: url("https://cdn.test");',
        );
        expect(scrubCss('background: url(/private/x.png)')).toBe(
            'background: url("[path]");',
        );
        expect(scrubCss('background: url("data:image/png;base64,AAAA")')).toBe(
            'background: url("");',
        );
    });

    it('keeps !important', () => {
        expect(scrubCss('top: 1px !important')).toBe('top: 1px !important;');
    });

    it('drops text it cannot parse and never throws', () => {
        expect(scrubCss('private note')).toBe('');
        expect(scrubCss('top: 1px; /* private')).toBe('top: 1px;');
        expect(scrubCss('top: 1px; content: "private')).toBe('top: 1px;');
        expect(scrubCss('')).toBe('');
    });

    it('applies the property list inside the rules of a style element', () => {
        const css = scrubCss(
            '.a { top: 1px; --x: 987654321; color: red } @media (min-width: 40rem) { .b { left: 2px; tab-size: 987654321 } }',
        );
        expect(css).toBe(
            '.a { top: 1px; } @media (min-width: 40rem) { .b { left: 2px; } }',
        );
    });

    it('handles a rule text from a stylesheet change record', () => {
        const css = scrubCss(
            '.late { content: "private"; --rule-note: private; top: 3px; }',
        );
        expect(css).toBe('.late { top: 3px; }');
    });
});

describe('scrubCss in a built stylesheet', () => {
    it('keeps design tokens and their numbers', () => {
        expect(built(':root { --spacing: 0.25rem; --accent: #d4e94c; }')).toBe(
            ':root { --spacing: 0.25rem; --accent: #d4e94c; }',
        );
    });

    it('keeps references, functions and listed words', () => {
        expect(
            built(
                '.a { padding: calc(var(--spacing) * 2); display: flex; transition: opacity 0.2s ease-out }',
            ),
        ).toBe(
            '.a { padding: calc(var(--spacing) * 2); display: flex; transition: opacity 0.2s ease-out; }',
        );
    });

    it('keeps a unicode range', () => {
        expect(
            built('@font-face { unicode-range: U+0000-00FF, U+0131; }'),
        ).toBe('@font-face { unicode-range: U+0000-00FF, U+0131; }');
    });

    it('resets a custom property that holds an unknown word', () => {
        expect(built(':root { --private-note: private note; }')).toBe(
            ':root { --private-note: initial; }',
        );
    });

    it('removes a normal declaration that holds an unknown word', () => {
        expect(built('.a { font-family: private note; top: 1px; }')).toBe(
            '.a { top: 1px; }',
        );
    });

    it('empties a quoted string and removes a comment', () => {
        const css = built(
            '.a { content: "private note"; /* private */ top: 1px; }',
        );
        expect(css).toBe('.a { content: ""; top: 1px; }');
    });

    it('empties a quoted string with an escaped quote', () => {
        expect(built('.a { content: "a\\"private"; }')).toBe(
            '.a { content: ""; }',
        );
    });

    it('empties a quoted attribute value in a selector', () => {
        expect(built('[data-x="private"] { top: 1px; }')).toBe(
            '[data-x=""] { top: 1px; }',
        );
    });

    it('keeps a number in an unlisted property', () => {
        expect(built('.a { tab-size: 4; }')).toBe('.a { tab-size: 4; }');
    });

    it('drops a custom property block with its content', () => {
        const css = built('.a { --x: { private note }; top: 1px; }');
        expect(css).not.toContain('private');
    });

    it('cuts a url to its origin', () => {
        expect(
            built('.a { background: url(https://cdn.test/a/b.png?x=1); }'),
        ).toBe('.a { background: url("https://cdn.test"); }');
    });

    it('keeps the names of a keyframes rule', () => {
        expect(
            built(
                '@keyframes lp-appear { from { opacity: 0; } to { opacity: 1; } }',
            ),
        ).toBe(
            '@keyframes lp-appear { from { opacity: 0; } to { opacity: 1; } }',
        );
    });
});

describe('the declaration count of a test', () => {
    it('reads an escaped quote in a selector as no string', () => {
        const css =
            ".\\[\\&_svg\\:not\\(\\[class\\*\\=\\'size-\\'\\]\\)\\]\\:size-4 { width: 1rem; } a { top: 0; }";

        expect(declarations(css)).toEqual(['width: 1rem', 'top: 0']);
    });
});

describe('the word and property lists', () => {
    it('hold lowercase entries only', () => {
        for (const word of [...KEPT_CSS_WORDS, ...KEPT_CSS_PROPERTIES]) {
            expect(word).toBe(word.toLowerCase());
        }
    });
});

function sourceFiles(directory) {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = `${directory}/${entry.name}`;
        if (entry.isDirectory()) {
            return sourceFiles(path);
        }

        return path.endsWith('.css') ? [path] : [];
    });
}

// The scrub may remove a comment, empty a string and cut a url. Normalise
// all three on both sides, so that any other difference is a lost declaration.
function normalise(css) {
    return css
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/\\.|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'/g, (match) =>
            match.startsWith('\\') ? match : '""',
        )
        .replace(/url\(\s*(?:""|[^)]*)\s*\)/gi, 'url()');
}

function declarations(css) {
    const found = [];
    for (const [, text, end] of normalise(css).matchAll(
        /([^;{}]*)([;{}]|$)/g,
    )) {
        const segment = text.trim().replace(/\s+/g, ' ');
        if (end !== '{' && segment.includes(':') && !segment.startsWith('@')) {
            found.push(segment);
        }
    }

    return found;
}

describe('the stylesheets of the application', () => {
    const files = sourceFiles('assets/styles');

    it('finds the stylesheets and their declarations', () => {
        expect(files.length).toBeGreaterThan(40);
        const total = files.reduce(
            (sum, file) =>
                sum + declarations(readFileSync(file, 'utf8')).length,
            0,
        );
        expect(total).toBeGreaterThan(500);
    });

    it.each(files)(
        'keeps every declaration of %s, add a word to KEPT_CSS_WORDS when this fails',
        (file) => {
            const source = readFileSync(file, 'utf8');
            const before = declarations(source);
            const after = declarations(built(source));

            expect(after).toEqual(before);
        },
    );
});

// Tailwind also compiles `[program:nginx]` in the supervisord files into an
// arbitrary property. It is no CSS property, so the replay loses nothing.
const NOT_CSS = /^program:/;
const COMPILED = 'var/tailwind/app.built.css';

describe.skipIf(!existsSync(COMPILED))(
    'the compiled Tailwind stylesheet',
    () => {
        it('keeps every declaration, add a word to KEPT_CSS_WORDS when this fails', () => {
            const source = readFileSync(COMPILED, 'utf8');
            const before = declarations(source).filter((d) => !NOT_CSS.test(d));
            const after = new Set(declarations(built(source)));

            expect(before.length).toBeGreaterThan(5000);
            expect(before.filter((d) => !after.has(d))).toEqual([]);
        });
    },
);
