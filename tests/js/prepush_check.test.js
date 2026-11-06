import { spawnSync } from 'node:child_process';
import {
    mkdirSync,
    mkdtempSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

const script = fileURLToPath(
    new URL(
        '../../plugins/loupe-lifecycle/skills/loupe-stage-implementation/references/prepush-check.sh',
        import.meta.url,
    ),
);
const repoProfile = fileURLToPath(
    new URL('../../.loupe/lifecycle.md', import.meta.url),
);

const gitEnv = {
    GIT_CONFIG_GLOBAL: '/dev/null',
    GIT_CONFIG_NOSYSTEM: '1',
    GIT_AUTHOR_NAME: 'Test',
    GIT_AUTHOR_EMAIL: 'test@loupe.test',
    GIT_COMMITTER_NAME: 'Test',
    GIT_COMMITTER_EMAIL: 'test@loupe.test',
};

const signed = (to) =>
    `https://card-1.loupe.dev.localhost/dev/preview-login?_hash=a1-_&email=demo%40loupe.test&to=${to}`;
const dataLine = `- [Run list](${signed('%2Fprojects%2Fp%2Fruns')}), marker \`data-run-id\`, empty \`/projects/e/runs\``;
const noDataLine = `- [New form](${signed('%2Ff')}), marker \`data-form\`, no data`;
const signedOutLine =
    '- [Landing](https://card-1.loupe.dev.localhost/), marker `data-hero`. Signed out.';

let dir;

function git(...args) {
    const r = spawnSync('git', args, {
        cwd: dir,
        env: { ...process.env, ...gitEnv },
        encoding: 'utf8',
    });
    expect(r.status, r.stderr).toBe(0);
}

function write(name, text) {
    mkdirSync(join(dir, name, '..'), { recursive: true });
    writeFileSync(join(dir, name), text);
}

function run(
    body,
    { notes = '', cardType = 'feature', prototype = 'no', base = 'base' } = {},
) {
    write('body.md', body);
    write('notes.md', notes);
    const r = spawnSync(
        'bash',
        [
            script,
            '--profile',
            join(dir, 'profile.md'),
            '--body',
            join(dir, 'body.md'),
            '--notes',
            join(dir, 'notes.md'),
            '--base',
            base,
            '--card-type',
            cardType,
            '--prototype',
            prototype,
        ],
        { cwd: dir, env: { ...process.env, ...gitEnv }, encoding: 'utf8' },
    );
    return { status: r.status, out: r.stdout };
}

beforeEach(() => {
    dir = mkdtempSync(join(tmpdir(), 'prepush-'));
    git('init', '-q', '-b', 'main');
    write('README.md', 'x\n');
    git('add', 'README.md');
    git('commit', '-q', '-m', 'init');
    git('tag', 'base');
    write('profile.md', readFileSync(repoProfile, 'utf8'));
    write('templates/page.html.twig', '<p>x</p>\n');
    git('add', 'templates/page.html.twig');
    git('commit', '-q', '-m', 'page');
});

afterEach(() => {
    rmSync(dir, { recursive: true, force: true });
});

describe('prepush-check.sh', () => {
    it('is clean when every form has a proven line', () => {
        const r = run(
            `## Preview\n\n${dataLine}\n${noDataLine}\n${signedOutLine}\n\n## Summary\n\ntext\n`,
        );
        expect(r.out).toBe('clean\n');
        expect(r.status).toBe(0);
    });

    it('names a missing section', () => {
        const r = run('## Summary\n\ntext\n');
        expect(r.status).toBe(1);
        expect(r.out).toContain('has no "## Preview" section');
    });

    it('names a section with no list line', () => {
        const r = run('## Preview\n\nnothing here\n');
        expect(r.status).toBe(1);
        expect(r.out).toContain('has no list line');
    });

    it.each([
        [
            'an ordinary link on a data line',
            '- [Run list](https://x.test/p), marker `m`, empty `/e`',
        ],
        [
            'an ordinary link on a no-data line',
            '- [Form](https://x.test/p), marker `m`, no data',
        ],
        [
            'an unsigned worktree link on a data line',
            '- [Run list](https://card-1.loupe.dev.localhost/projects/p), marker `m`, empty `/e`',
        ],
        [
            'a signed link with no hash',
            '- [Form](https://card-1.loupe.dev.localhost/dev/preview-login?email=a%40b.c&to=%2Ff), marker `m`, no data',
        ],
        [
            'a signed link on another host',
            '- [Form](https://evil.test/dev/preview-login?_hash=a&email=a%40b.c&to=%2Ff), marker `m`, no data',
        ],
        [
            'an ordinary link on a signed-out line',
            '- [Landing](https://x.test/), marker `m`. Signed out.',
        ],
        [
            'a signed link on a signed-out line',
            '- [Landing](https://card-1.loupe.dev.localhost/dev/preview-login?_hash=a&email=a%40b.c&to=%2F), marker `m`. Signed out.',
        ],
    ])('rejects %s', (_name, line) => {
        const r = run(`## Preview\n\n${line}\n`);
        expect(r.status).toBe(1);
        expect(r.out).toContain('fits no line pattern');
    });

    it('rejects an ordinary link on a records state', () => {
        const line =
            '- [Run list](https://x.test/p), marker `data-run-id`, empty `/projects/e/runs`';
        const r = run(`## Preview\n\n${line}\n`, {
            notes: '## Preview\n\n- [Run list](/projects/p/runs), marker `data-run-id`, records\n',
        });
        expect(r.status).toBe(1);
        expect(r.out).toContain('"Run list" shows records');
    });

    it('names a data line with no empty twin', () => {
        const r = run(
            `## Preview\n\n- [Run list](${signed('%2Fa')}), marker \`data-run-id\`, no data\n- [Run list 2](${signed('%2Fb')}), marker \`data-run-id\`\n`,
        );
        expect(r.status).toBe(1);
        expect(r.out).toContain('fits no line pattern');
        expect(r.out).toContain('Run list 2');
    });

    it('names a records state whose line has no empty twin', () => {
        const r = run(
            `## Preview\n\n- [Run list](${signed('%2Fa')}), marker \`data-run-id\`, no data\n`,
            {
                notes: '## Preview\n\n- [Run list](/projects/p/runs), marker `data-run-id`, records\n',
            },
        );
        expect(r.status).toBe(1);
        expect(r.out).toContain('"Run list" shows records');
        expect(r.out).toContain('empty twin');
    });

    it('accepts a records state with its empty twin', () => {
        const r = run(`## Preview\n\n${dataLine}\n${noDataLine}\n`, {
            notes: '## Preview\n\n- [Run list](/projects/p/runs), marker `data-run-id`, records\n- [New form](/f), marker `data-records`, no records\n',
        });
        expect(r.out).toBe('clean\n');
        expect(r.status).toBe(0);
    });

    it('names a notes state that says neither records nor no records', () => {
        const r = run(`## Preview\n\n${dataLine}\n`, {
            notes: '## Preview\n\n- [Run list](/projects/p/runs), marker `data-records`\n',
        });
        expect(r.status).toBe(1);
        expect(r.out).toContain('does not say whether "Run list"');
    });

    it('refuses a base it cannot read instead of returning clean', () => {
        const r = run('## Summary\n', { base: 'no-such-ref' });
        expect(r.status).toBe(64);
        expect(r.out).not.toContain('clean');
    });

    it('refuses a Preview section with no Paths folder', () => {
        write(
            'profile.md',
            readFileSync(repoProfile, 'utf8').replace(
                /^1\. Paths: .*$/m,
                '1. Paths: none.',
            ),
        );
        const r = run('## Summary\n');
        expect(r.status).toBe(64);
        expect(r.out).not.toContain('clean');
    });

    it('exits 2 when the only gaps are Not proven lines', () => {
        const r = run(
            `## Preview\n\n${dataLine}\n- Run list: Not proven: cannot seed\n`,
        );
        expect(r.status).toBe(2);
        expect(r.out).toContain('not-proven: - Run list: Not proven');
        expect(r.out).not.toContain('gap:');
    });

    it('exits 1 when a gap sits next to a Not proven line', () => {
        const r = run('## Summary\n\n- Run list: Not proven: cannot seed\n');
        expect(r.status).toBe(1);
    });

    it('names a state of the build notes that the body lacks', () => {
        const r = run(`## Preview\n\n${dataLine}\n`, {
            notes: '## Preview\n\n- [Run list](/a), marker `m`\n- [Settings](/b), marker `m`\n\n## Tasks\n',
        });
        expect(r.status).toBe(1);
        expect(r.out).toContain('names "Settings"');
        expect(r.out).not.toContain('names "Run list"');
    });

    it('skips the Preview for an epic', () => {
        const r = run('## Summary\n', { cardType: 'epic' });
        expect(r.out).toBe('clean\n');
    });

    it('skips the Preview when no changed path counts', () => {
        git('mv', 'templates/page.html.twig', 'README2.md');
        git('commit', '-q', '-m', 'move');
        const r = run('## Summary\n');
        expect(r.out).toBe('clean\n');
    });

    it('skips an excluded path', () => {
        git('reset', '-q', '--hard', 'base');
        write('templates/Module/Mail/email/welcome.html.twig', 'x\n');
        git('add', '-A');
        git('commit', '-q', '-m', 'email');
        const r = run('## Summary\n');
        expect(r.out).toBe('clean\n');
    });

    it('skips the Preview when the profile has no Preview section', () => {
        write(
            'profile.md',
            readFileSync(repoProfile, 'utf8').replace(
                /\n## Preview\n[\s\S]*?(?=\n## Visual check)/,
                '',
            ),
        );
        const r = run('## Summary\n');
        expect(r.out).toBe('clean\n');
    });

    it('falls back to a link and a backtick marker without line patterns', () => {
        write(
            'profile.md',
            readFileSync(repoProfile, 'utf8').replace(
                /\n {3,4}```[\s\S]*?```\n/g,
                '\n',
            ),
        );
        const ok = run('## Preview\n\n- [A](https://x.test), marker `m`\n');
        expect(ok.out).toBe('clean\n');
        const bad = run('## Preview\n\n- A: https://x.test\n');
        expect(bad.status).toBe(1);
        expect(bad.out).toContain('no Markdown link');
        expect(bad.out).toContain('no marker in backticks');
    });

    it('names a missing Prototype map', () => {
        const r = run(`## Preview\n\n${dataLine}\n`, {
            prototype: 'yes',
            notes: '## Tasks\n\n- one\n',
        });
        expect(r.status).toBe(1);
        expect(r.out).toContain('Prototype map');
    });

    it('accepts a Prototype map with content', () => {
        const r = run(`## Preview\n\n${dataLine}\n`, {
            prototype: 'yes',
            notes: '## Prototype map\n\n| Part | Page |\n\n## Tasks\n',
        });
        expect(r.out).toBe('clean\n');
    });

    it('refuses a missing argument', () => {
        const r = spawnSync('bash', [script, '--base', 'base'], {
            encoding: 'utf8',
        });
        expect(r.status).toBe(64);
    });
});
