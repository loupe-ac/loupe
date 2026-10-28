import { spawnSync } from 'node:child_process';
import {
    chmodSync,
    mkdirSync,
    mkdtempSync,
    realpathSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

const script = fileURLToPath(
    new URL('../../bin/worktrees/bridge-before.sh', import.meta.url),
);

const gitEnv = {
    GIT_CONFIG_GLOBAL: '/dev/null',
    GIT_CONFIG_NOSYSTEM: '1',
    GIT_AUTHOR_NAME: 'Test',
    GIT_AUTHOR_EMAIL: 'test@loupe.test',
    GIT_COMMITTER_NAME: 'Test',
    GIT_COMMITTER_EMAIL: 'test@loupe.test',
};

let dir;
let main;
let holder;

function git(cwd, ...args) {
    const run = spawnSync('git', args, {
        cwd,
        encoding: 'utf8',
        env: { ...process.env, ...gitEnv },
    });
    expect(run.status, run.stderr).toBe(0);

    return run.stdout.trim();
}

function bridgeBefore() {
    return spawnSync('bash', [script, '--git-only', '7', 'card-id', '12'], {
        cwd: main,
        encoding: 'utf8',
        env: {
            ...process.env,
            ...gitEnv,
            PATH: `${join(dir, 'stubs')}:${process.env.PATH}`,
        },
    });
}

beforeEach(() => {
    dir = realpathSync(mkdtempSync(join(tmpdir(), 'bridge-before-')));
    mkdirSync(join(dir, 'stubs'));
    writeFileSync(join(dir, 'stubs', 'gh'), '#!/usr/bin/env bash\necho feat\n');
    chmodSync(join(dir, 'stubs', 'gh'), 0o755);

    git(dir, 'init', '--quiet', '--bare', '-b', 'main', 'origin.git');
    git(dir, 'clone', '--quiet', 'origin.git', 'main');
    main = join(dir, 'main');
    git(main, 'commit', '--quiet', '--allow-empty', '-m', 'first');
    git(main, 'push', '--quiet', 'origin', 'HEAD:main', 'HEAD:feat');
    git(main, 'fetch', '--quiet', 'origin');
    holder = join(main, '.worktrees', 'session tree');
    git(
        main,
        'worktree',
        'add',
        '--quiet',
        '-b',
        'feat',
        holder,
        'origin/feat',
    );
});

afterEach(() => rmSync(dir, { recursive: true, force: true }));

describe('bridge-before.sh with a branch that another worktree holds', () => {
    it('detaches a clean and pushed holder, then checks the branch out', () => {
        const run = bridgeBefore();

        expect(run.status, run.stderr).toBe(0);
        expect(run.stdout.trim()).toBe(join(main, '.worktrees', 'card-7'));
        expect(
            git(join(main, '.worktrees', 'card-7'), 'branch', '--show-current'),
        ).toBe('feat');
        expect(git(holder, 'branch', '--show-current')).toBe('');
    });

    it('refuses a holder with uncommitted work, and names it', () => {
        writeFileSync(join(holder, 'draft.txt'), 'work');

        const run = bridgeBefore();

        expect(run.status).toBe(1);
        expect(run.stderr).toContain(`Commit and push that work in ${holder}`);
        expect(run.stderr).not.toMatch(/run 'just worktree-down/);
        expect(git(holder, 'branch', '--show-current')).toBe('feat');
    });

    it('refuses a holder with an unpushed commit', () => {
        git(holder, 'commit', '--quiet', '--allow-empty', '-m', 'local');

        const run = bridgeBefore();

        expect(run.status).toBe(1);
        expect(run.stderr).toContain('uncommitted or unpushed work');
        expect(git(holder, 'branch', '--show-current')).toBe('feat');
    });

    it('frees the branch when the card worktree already exists detached', () => {
        const card = join(main, '.worktrees', 'card-7');
        git(
            main,
            'worktree',
            'add',
            '--quiet',
            '--detach',
            card,
            'origin/main',
        );

        const run = bridgeBefore();

        expect(run.status, run.stderr).toBe(0);
        expect(git(holder, 'branch', '--show-current')).toBe('');
        git(card, 'switch', '--quiet', 'feat');
    });

    it('keeps the branch in the card worktree that already holds it', () => {
        const card = join(main, '.worktrees', 'card-7');
        git(main, 'worktree', 'remove', holder);
        git(main, 'worktree', 'add', '--quiet', card, 'feat');

        const run = bridgeBefore();

        expect(run.status, run.stderr).toBe(0);
        expect(git(card, 'branch', '--show-current')).toBe('feat');
    });

    it('refuses to detach the main checkout', () => {
        git(main, 'worktree', 'remove', holder);
        git(main, 'checkout', '--quiet', 'feat');

        const run = bridgeBefore();

        expect(run.status).toBe(1);
        expect(run.stderr).toContain('the main checkout holds feat');
        expect(git(main, 'branch', '--show-current')).toBe('feat');
    });
});
