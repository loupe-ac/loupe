import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = fileURLToPath(new URL('../../', import.meta.url));

function shellFiles(dir) {
    return readdirSync(join(root, dir), {
        withFileTypes: true,
        recursive: true,
    })
        .filter((entry) => entry.isFile() && entry.name.endsWith('.sh'))
        .map((entry) => relative(root, join(entry.parentPath, entry.name)));
}

// A reader that exits before EOF sends SIGPIPE to git once the output passes the pipe buffer.
const earlyExit =
    /worktree list --porcelain\s*\|\s*(awk\b[^|]*\bexit\b|grep\s+-\w*[qm]|head\b|sed\b[^|]*q)/;

describe('git worktree list output', () => {
    const files = [
        ...shellFiles('bin'),
        ...shellFiles('.agents/hooks'),
        'justfile',
    ];

    it.each(files)(
        '%s never pipes it into a reader that exits early',
        (file) => {
            const offenders = readFileSync(join(root, file), 'utf8')
                .split('\n')
                .map((line, index) => ({
                    line: line.trim(),
                    number: index + 1,
                }))
                .filter(({ line }) => earlyExit.test(line));

            expect(offenders).toEqual([]);
        },
    );

    it('catches the pattern it guards against', () => {
        expect(
            earlyExit.test(
                `git worktree list --porcelain | awk '/^worktree /{print $2; exit}'`,
            ),
        ).toBe(true);
        expect(
            earlyExit.test(
                'git -C "$main" worktree list --porcelain | grep -qxF "worktree $root"',
            ),
        ).toBe(true);
        expect(
            earlyExit.test(
                `git worktree list --porcelain | awk '/^worktree /{print $2}'`,
            ),
        ).toBe(false);
    });
});
