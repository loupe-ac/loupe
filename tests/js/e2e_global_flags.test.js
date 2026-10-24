import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = new URL('../../', import.meta.url).pathname;
const config = readFileSync(join(root, 'e2e/playwright.config.ts'), 'utf8');

function project(name) {
    const start = config.indexOf(`name: '${name}'`);
    expect(start).toBeGreaterThan(-1);
    const end = config.indexOf('\n        },', start);
    return config.slice(start, end);
}

function patterns(block, key) {
    const list = block.slice(block.indexOf(`${key}: [`));
    return [
        ...list.slice(0, list.indexOf(']')).matchAll(/^\s*\/(.+)\/,$/gm),
    ].map(([, source]) => new RegExp(source));
}

function specs(dir) {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) return specs(path);
        return entry.name.endsWith('.spec.ts') ? [path] : [];
    });
}

describe('e2e projects', () => {
    const flagging = specs(join(root, 'e2e/tests'))
        .filter((path) =>
            readFileSync(path, 'utf8').includes('/dev/e2e/feature-flag'),
        )
        .map((path) => relative(join(root, 'e2e/tests'), path));
    const serial = patterns(project('global-flags'), 'testMatch');
    const ignored = patterns(project('chromium'), 'testIgnore');

    it('finds the specs that change a global flag', () => {
        expect(flagging.length).toBeGreaterThan(0);
    });

    it.each(flagging)(
        '%s runs in global-flags and never in chromium',
        (spec) => {
            expect(serial.some((pattern) => pattern.test(spec))).toBe(true);
            expect(ignored.some((pattern) => pattern.test(spec))).toBe(true);
        },
    );
});
