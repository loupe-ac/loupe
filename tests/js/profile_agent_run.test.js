import { spawnSync } from 'node:child_process';
import {
    chmodSync,
    mkdirSync,
    mkdtempSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { delimiter, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

const script = fileURLToPath(
    new URL('../skill-evals/profile-agent/run.sh', import.meta.url),
);

const sections = [
    'Instruction files',
    'Environment',
    'Gate',
    'Code review',
    'Pull request',
    'Board',
    'Merge',
    'Repair',
]
    .map((name) => `## ${name}`)
    .join('\\n');

// Stands in for both agents: it reads the workflow, writes the files in the fixture,
// and adds and pushes only the files that FAKE_PUSH names.
const fakeAgent = `#!/bin/sh
echo workflow_get >> ../mcp-calls.log
echo '{"name":"Skill","input":{"skill":"loupe-lifecycle:loupe-profile"}} loupe-profile/SKILL.md'
mkdir -p .loupe
printf '${sections}\\npython3 -m unittest discover -s tests\\n' > .loupe/lifecycle.md
echo 'project: p1' > .loupe.yaml
if [ -n "$FAKE_PUSH" ]; then
    git add $FAKE_PUSH
    git commit -q -m 'Add the profile'
    git push -q origin main
fi
`;

describe.each(['claude', 'codex'])('the profile evaluation of %s', (agent) => {
    let dir;

    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'profile-agent-verdict-'));
        mkdirSync(join(dir, 'bin'));
        writeFileSync(join(dir, 'bin', agent), fakeAgent);
        chmodSync(join(dir, 'bin', agent), 0o755);
        writeFileSync(
            join(dir, 'gitconfig'),
            '[user]\n\tuseConfigOnly = true\n',
        );
    });

    afterEach(() => rmSync(dir, { recursive: true, force: true }));

    const run = (push) =>
        spawnSync('bash', [script, agent], {
            encoding: 'utf8',
            env: {
                ...process.env,
                FAKE_PUSH: push,
                // A machine with no git identity, as CI is: only the fixture can give one.
                EMAIL: undefined,
                GIT_AUTHOR_EMAIL: undefined,
                GIT_AUTHOR_NAME: undefined,
                GIT_COMMITTER_EMAIL: undefined,
                GIT_COMMITTER_NAME: undefined,
                GIT_CONFIG_SYSTEM: join(dir, 'gitconfig'),
                HOME: dir,
                XDG_CONFIG_HOME: dir,
                PATH: `${join(dir, 'bin')}${delimiter}${process.env.PATH}`,
                TMPDIR: dir,
            },
        });

    it('passes when both files reach the remote', () => {
        const result = run('.loupe/lifecycle.md .loupe.yaml');

        expect(result.stdout).not.toContain('FAIL');
        expect(result.status).toBe(0);
    });

    it('fails when neither file reaches the remote', () => {
        const result = run('');

        expect(result.stdout).toContain('FAIL profile-on-remote');
        expect(result.stdout).toContain('FAIL project-file-on-remote');
        expect(result.status).toBe(1);
    });

    it('fails when the project file stays local', () => {
        const result = run('.loupe/lifecycle.md');

        expect(result.stdout).toContain('pass profile-on-remote');
        expect(result.stdout).toContain('FAIL project-file-on-remote');
        expect(result.status).toBe(1);
    });
});
