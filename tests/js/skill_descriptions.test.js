import { readdirSync, readFileSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const MAX_LENGTH = 200;
const root = fileURLToPath(new URL('../../', import.meta.url));
const folders = ['plugins/loupe/skills', '.agents/skills'];

function descriptions() {
    const found = [];
    for (const folder of folders) {
        for (const name of readdirSync(root + folder)) {
            const file = `${root}${folder}/${name}/SKILL.md`;
            if (!statSync(root + folder + '/' + name).isDirectory()) {
                continue;
            }
            const line = readFileSync(file, 'utf8')
                .split('---')[1]
                .split('\n')
                .find((l) => l.startsWith('description:'));
            found.push([
                `${folder}/${name}`,
                line.slice('description:'.length).trim(),
            ]);
        }
    }

    return found;
}

describe('skill descriptions', () => {
    it.each(descriptions())(
        '%s is one quoted line of at most 200 characters',
        (_name, raw) => {
            expect(raw).toMatch(/^".*"$/);
            expect(JSON.parse(raw).length).toBeLessThanOrEqual(MAX_LENGTH);
        },
    );
});
