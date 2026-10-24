import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const RULE =
    'Every card drawer opener must name its loading title in data-drawer-title, or the drawer opens with a blank header.';
const OPENER = /card-drawer#prepare|data-card-drawer-opener/;

function twigFilesUnder(directory) {
    return readdirSync(join(ROOT, directory)).flatMap((name) => {
        const path = join(directory, name);
        if (statSync(join(ROOT, path)).isDirectory()) {
            return twigFilesUnder(path);
        }

        return name.endsWith('.twig') ? [path] : [];
    });
}

/** Each start tag of the source, read up to the first `>` outside quotes, with its line. */
function startTags(source) {
    const tags = [];
    for (let start = source.indexOf('<'); start !== -1; ) {
        let quote = null;
        let end = start + 1;
        for (; end < source.length; end++) {
            const char = source[end];
            if (quote) {
                if (char === quote) quote = null;
            } else if (char === '"' || char === "'") {
                quote = char;
            } else if (char === '>') {
                break;
            }
        }
        tags.push({
            tag: source.slice(start, end + 1),
            line: source.slice(0, start).split('\n').length,
        });
        start = source.indexOf('<', end);
    }

    return tags;
}

describe('card drawer openers', () => {
    it('reads a tag whose action holds an arrow as one tag', () => {
        const [link] = startTags(
            '<a href="/x"\n data-action="click->card-drawer#prepare" data-drawer-title="#1">',
        );
        expect(link.tag).toContain('data-drawer-title');
    });

    it('name their loading title', () => {
        const files = twigFilesUnder('templates');
        const openers = files.flatMap((file) =>
            startTags(readFileSync(join(ROOT, file), 'utf8'))
                .filter(({ tag }) => OPENER.test(tag))
                .map((opener) => ({ ...opener, file })),
        );

        expect(openers.length).toBeGreaterThan(10);
        const untitled = openers
            .filter(({ tag }) => !tag.includes('data-drawer-title'))
            .map(({ file, line }) => `${file}:${line}`);
        expect(untitled, RULE).toEqual([]);
    });
});
