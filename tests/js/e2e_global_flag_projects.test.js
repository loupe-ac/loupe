import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = new URL('../..', import.meta.url).pathname;
const config = readFileSync(join(root, 'e2e/playwright.config.ts'), 'utf8');

function patterns(project, key) {
    const start = config.indexOf(`name: '${project}'`);
    const open = config.indexOf(`${key}: [`, start);
    const block = config.slice(open, config.indexOf(']', open));

    return block
        .split('\n')
        .map((line) => line.trim().replace(/,$/, ''))
        .filter((line) => line.startsWith('/') && !line.startsWith('//'))
        .map((line) => new RegExp(line.slice(1, -1)));
}

function specs(dir) {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) {
            return specs(path);
        }

        return entry.name.endsWith('.spec.ts') ? [path] : [];
    });
}

const flagSpecs = specs(join(root, 'e2e/tests'))
    .filter((path) =>
        readFileSync(path, 'utf8').includes('/dev/e2e/feature-flag'),
    )
    .map((path) => relative(join(root, 'e2e'), path));

describe('e2e specs that change a global flag', () => {
    const ignored = patterns('chromium', 'testIgnore');
    const serial = patterns('global-flags', 'testMatch');

    it('finds the specs and the project patterns', () => {
        expect(flagSpecs.length).toBeGreaterThan(0);
        expect(ignored.length).toBeGreaterThan(0);
        expect(serial.length).toBeGreaterThan(0);
    });

    it.each(flagSpecs)(
        '%s stays out of the parallel chromium project',
        (spec) => {
            expect(ignored.some((pattern) => pattern.test(spec))).toBe(true);
        },
    );

    it.each(flagSpecs)('%s runs in the serial global-flags project', (spec) => {
        expect(serial.some((pattern) => pattern.test(spec))).toBe(true);
    });
});
