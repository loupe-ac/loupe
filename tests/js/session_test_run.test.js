import { spawnSync } from 'node:child_process';
import {
    chmodSync,
    mkdirSync,
    mkdtempSync,
    readdirSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { delimiter, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

const script = fileURLToPath(
    new URL('../skill-evals/session-test/run.mjs', import.meta.url),
);

const fakeClaude = `#!/usr/bin/env node
const fs = require('node:fs');
const argv = process.argv;
const config = JSON.parse(fs.readFileSync(argv[argv.indexOf('--mcp-config') + 1], 'utf8'));
const log = config.mcpServers.loupe.env.LOUPE_STUB_LOG;
const call = (tool, args) => fs.appendFileSync(log, JSON.stringify({ tool, args }) + '\\n');
const draft = process.env.FAKE_DRAFT === 'none' ? {} : { draft: true };
call('document_create', {
    title: 'Notes by brainstorming from superpowers',
    markdown: process.env.FAKE_MARKDOWN ?? '## Decisions log\\n\\n1. Method: brainstorming from superpowers.\\n',
    ...draft,
});
if (process.env.FAKE_REVISION) call('document_revise', { markdown: process.env.FAKE_REVISION });
if (process.env.FAKE_CLOSE === '1') call('card_run_close', {});
console.log(JSON.stringify({
    type: 'assistant',
    message: { content: [{ type: 'tool_use', name: 'Skill', input: { skill: 'brainstorming' } }] },
}));
if (process.env.FAKE_RESULT !== 'none') {
    console.log(JSON.stringify({ type: 'result', is_error: process.env.FAKE_RESULT === 'error', total_cost_usd: 0 }));
}
process.exit(Number(process.env.FAKE_EXIT || 0));
`;

describe('the session test verdict', () => {
    let dir;

    beforeEach(() => {
        dir = mkdtempSync(join(tmpdir(), 'session-test-verdict-'));
        mkdirSync(join(dir, 'bin'));
        writeFileSync(join(dir, 'bin/claude'), fakeClaude);
        chmodSync(join(dir, 'bin/claude'), 0o755);
        mkdirSync(join(dir, 'skill/skills/brainstorming'), { recursive: true });
        writeFileSync(join(dir, 'skill/skills/brainstorming/SKILL.md'), 'x');
    });

    afterEach(() => {
        rmSync(dir, { recursive: true, force: true });
    });

    const run = (env, kind = 'product') =>
        spawnSync(
            process.execPath,
            [
                script,
                kind,
                '--out',
                join(dir, 'out'),
                '--skill-plugin',
                join(dir, 'skill'),
                '--max-turns',
                '2',
            ],
            {
                encoding: 'utf8',
                env: {
                    ...process.env,
                    PATH: `${join(dir, 'bin')}${delimiter}${process.env.PATH}`,
                    ...env,
                },
            },
        );

    it('passes a session whose turns succeed and that closes its run', () => {
        const result = run({ FAKE_CLOSE: '1' });

        expect(result.stdout).not.toContain('FAIL');
        expect(result.status).toBe(0);
    });

    it.each([
        ['a nonzero exit', { FAKE_EXIT: '1', FAKE_CLOSE: '1' }],
        ['an error result', { FAKE_RESULT: 'error', FAKE_CLOSE: '1' }],
        ['no result event', { FAKE_RESULT: 'none', FAKE_CLOSE: '1' }],
    ])('fails a turn that ends with %s', (_label, env) => {
        const result = run(env);

        expect(result.stdout).toContain('FAIL  every claude turn succeeded');
        expect(result.status).toBe(1);
    });

    it('fails a session that creates its document without a draft', () => {
        const result = run({ FAKE_CLOSE: '1', FAKE_DRAFT: 'none' });

        expect(result.stdout).toContain(
            'FAIL  every document_create asks for a draft',
        );
        expect(result.status).toBe(1);
    });

    const logFailure = 'FAIL  decision entry names the skill and its source';
    const named = 'brainstorming from superpowers';

    it.each([
        ['product', `## Decisions log\n\n1. Method: ${named}.\n`],
        [
            'product',
            `## Decisions log\n\n1. Method: brainstorming.\n\n   Source: superpowers.\n`,
        ],
        [
            'product',
            `## Decisions log\n\n\`\`\`text\n## Other\n\`\`\`\n\n- Method: ${named}.\n`,
        ],
        ['tech', `## Decided\n\nThe owner picked ${named}.\n`],
    ])('passes a %s session with the entry in %j', (kind, markdown) => {
        const result = run({ FAKE_CLOSE: '1', FAKE_MARKDOWN: markdown }, kind);

        expect(result.stdout).not.toContain(logFailure);
        expect(result.status).toBe(0);
    });

    it.each([
        ['product', `## At a glance\n\n${named}\n`],
        [
            'product',
            `## Problem\n\n${named}\n\n## Decisions log\n\n1. Scope: small.\n`,
        ],
        ['product', `## Decided\n\n${named}\n`],
        ['tech', `## Decisions log\n\n1. Method: ${named}.\n`],
        [
            'product',
            `## Decisions log\n\n1. Method: brainstorming.\n2. Plugin: superpowers.\n`,
        ],
        [
            'product',
            `## Decisions log\n\n| Question | Answer |\n|---|---|\n| Method | brainstorming |\n| Plugin | superpowers |\n`,
        ],
        [
            'product',
            `## Notes\n\n\`\`\`text\n## Decisions log\n\n${named}\n\`\`\`\n`,
        ],
    ])('fails a %s session with no entry in %j', (kind, markdown) => {
        const result = run({ FAKE_CLOSE: '1', FAKE_MARKDOWN: markdown }, kind);

        expect(result.stdout).toContain(logFailure);
        expect(result.status).toBe(1);
    });

    it('reads the entry from the last version of the document', () => {
        const added = run({
            FAKE_CLOSE: '1',
            FAKE_MARKDOWN: '## Decisions log\n\n1. Scope: small.\n',
            FAKE_REVISION: `## Decisions log\n\n1. Method: ${named}.\n`,
        });
        const removed = run({
            FAKE_CLOSE: '1',
            FAKE_REVISION: '## Decisions log\n\n1. Scope: small.\n',
        });

        expect(added.stdout).not.toContain(logFailure);
        expect(removed.stdout).toContain(logFailure);
    });

    it('makes no temporary folder when the output folder is given', () => {
        const temporary = join(dir, 'tmp');
        mkdirSync(temporary);

        run({ FAKE_CLOSE: '1', TMPDIR: temporary });

        expect(readdirSync(temporary)).toEqual([]);
    });

    it('fails a session that uses every turn and never closes its run', () => {
        const result = run({});

        expect(result.stdout).toContain(
            'FAIL  session finished with card_run_close',
        );
        expect(result.status).toBe(1);
    });
});
