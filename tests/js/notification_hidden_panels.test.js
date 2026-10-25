import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const TEMPLATES = 'templates/Module/Notification';
const STYLES = ['assets/styles/app.css'].concat(
    readdirSync(join(ROOT, 'assets/styles/components')).map(
        (name) => `assets/styles/components/${name}`,
    ),
);
const DISPLAY = new Set([
    'block',
    'inline-block',
    'flex',
    'inline-flex',
    'grid',
    'inline-grid',
    'table',
    'contents',
]);

const css = STYLES.map((path) => readFileSync(join(ROOT, path), 'utf8'))
    .join('\n')
    .replace(/\/\*[\s\S]*?\*\//g, '');

/** Each innermost rule of the stylesheets, as its selector list and its body. */
const rules = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((match) => ({
    selectors: match[1].split(',').map((selector) => selector.trim()),
    body: match[2],
}));

function setsDisplay(body) {
    const applied = body.match(/@apply\s+([^;]+);/);
    const utilities = applied ? applied[1].split(/\s+/) : [];

    return (
        /(^|[\s;])display\s*:/.test(body) ||
        utilities.some((utility) => DISPLAY.has(utility))
    );
}

/** The classes of each start tag that carries a bare `hidden` attribute. */
function hiddenClasses() {
    return readdirSync(join(ROOT, TEMPLATES))
        .filter((name) => name.endsWith('.twig'))
        .flatMap((name) => {
            const source = readFileSync(join(ROOT, TEMPLATES, name), 'utf8');

            return [
                ...source.matchAll(/<[a-z][^<>]*\shidden[\s>][^<>]*>?/g),
            ].flatMap((tag) => {
                const classes = tag[0].match(/\sclass="([^"]*)"/);

                return classes ? classes[1].split(/\s+/).filter(Boolean) : [];
            });
        });
}

describe('hidden notification panels', () => {
    it('finds the hidden panels of the settings page', () => {
        expect(hiddenClasses()).toContain('lp-settings-panel');
    });

    it.each([...new Set(hiddenClasses())])(
        'keeps a hidden .%s out of the layout when its rule sets a display',
        (className) => {
            const displayed = rules.some(
                (rule) =>
                    rule.selectors.includes(`.${className}`) &&
                    setsDisplay(rule.body),
            );
            const hiddenRule = rules.some((rule) =>
                rule.selectors.includes(`.${className}[hidden]`),
            );

            expect(
                !displayed || hiddenRule,
                `.${className} sets a display, which outranks the UA [hidden] rule. Add .${className}[hidden] { display: none; }.`,
            ).toBe(true);
        },
    );
});
