import { spawnSync } from 'node:child_process';
import {
    chmodSync,
    existsSync,
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

function bridgeBefore(args = ['--git-only', '7', 'card-id', '12']) {
    return spawnSync('bash', [script, ...args], {
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

describe('bridge-before.sh with an epic branch', () => {
    const card = () => join(main, '.worktrees', 'card-7');

    beforeEach(() => {
        git(main, 'worktree', 'remove', holder);
        git(main, 'branch', '-D', 'feat');
        git(main, 'commit', '--quiet', '--allow-empty', '-m', 'epic work');
        git(main, 'push', '--quiet', 'origin', 'HEAD:epic/852');
    });

    it('cuts a new worktree from the epic branch', () => {
        const run = bridgeBefore([
            '--git-only',
            '--epic-branch=epic/852',
            '7',
            'card-id',
        ]);

        expect(run.status, run.stderr).toBe(0);
        expect(run.stdout.trim()).toBe(card());
        expect(git(card(), 'branch', '--show-current')).toBe('');
        expect(git(card(), 'rev-parse', 'HEAD')).toBe(
            git(main, 'rev-parse', 'origin/epic/852'),
        );
        expect(git(card(), 'rev-parse', 'HEAD')).not.toBe(
            git(main, 'rev-parse', 'origin/main'),
        );
    });

    it('takes the option before or after --git-only', () => {
        const run = bridgeBefore([
            '--epic-branch=epic/852',
            '--git-only',
            '7',
            'card-id',
        ]);

        expect(run.status, run.stderr).toBe(0);
        expect(git(card(), 'rev-parse', 'HEAD')).toBe(
            git(main, 'rev-parse', 'origin/epic/852'),
        );
    });

    it('stops and names a branch that is missing on origin, with no fallback to main', () => {
        const run = bridgeBefore([
            '--git-only',
            '--epic-branch=epic/999',
            '7',
            'card-id',
        ]);

        expect(run.status).not.toBe(0);
        expect(run.stderr).toContain('epic/999');
        expect(existsSync(card())).toBe(false);
    });

    it('cuts from main when the option is empty', () => {
        const run = bridgeBefore([
            '--git-only',
            '--epic-branch=',
            '7',
            'card-id',
        ]);

        expect(run.status, run.stderr).toBe(0);
        expect(git(card(), 'rev-parse', 'HEAD')).toBe(
            git(main, 'rev-parse', 'origin/main'),
        );
    });

    it.each(['-bad', 'a..b', 'epic/$(x)', 'epic branch', 'epic/'])(
        'refuses the name %s',
        (name) => {
            const run = bridgeBefore([
                '--git-only',
                `--epic-branch=${name}`,
                '7',
                'card-id',
            ]);

            expect(run.status).not.toBe(0);
            expect(existsSync(card())).toBe(false);
        },
    );

    it('leaves an existing worktree where it is', () => {
        git(
            main,
            'worktree',
            'add',
            '--quiet',
            '--detach',
            card(),
            'origin/main',
        );

        const run = bridgeBefore([
            '--git-only',
            '--epic-branch=epic/852',
            '7',
            'card-id',
        ]);

        expect(run.status, run.stderr).toBe(0);
        expect(git(card(), 'rev-parse', 'HEAD')).toBe(
            git(main, 'rev-parse', 'origin/main'),
        );
    });
});
