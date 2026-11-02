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
    new URL('../skill-evals/session-test/run.mjs', import.meta.url),
);

const fakeClaude = `#!/usr/bin/env node
const fs = require('node:fs');
const argv = process.argv;
const config = JSON.parse(fs.readFileSync(argv[argv.indexOf('--mcp-config') + 1], 'utf8'));
const log = config.mcpServers.loupe.env.LOUPE_STUB_LOG;
const call = (tool, args) => fs.appendFileSync(log, JSON.stringify({ tool, args }) + '\\n');
const draft = process.env.FAKE_DRAFT === 'none' ? {} : { draft: true };
call('document_create', { markdown: 'brainstorming from superpowers', ...draft });
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

    const run = (env) =>
        spawnSync(
            process.execPath,
            [
                script,
                'product',
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

    it('fails a session that uses every turn and never closes its run', () => {
        const result = run({});

        expect(result.stdout).toContain(
            'FAIL  session finished with card_run_close',
        );
        expect(result.status).toBe(1);
    });
});
