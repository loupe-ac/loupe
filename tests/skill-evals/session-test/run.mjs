#!/usr/bin/env node
// Real session test: does a Loupe design session stop a third-party
// brainstorming skill from writing a spec file, committing it and handing
// off to a planning skill? See README.md.
//
// usage: node run.mjs <product|tech> [--out DIR] [--skill-plugin DIR] [--model M] [--max-cost USD] [--max-turns N]
import { spawnSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import {
    cpSync,
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    rmSync,
    writeFileSync,
    appendFileSync,
    statSync,
} from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');
const args = process.argv.slice(2);
const kind = args.shift();
if (!['product', 'tech'].includes(kind)) {
    console.error('usage: node run.mjs <product|tech> [options]');
    process.exit(2);
}
const opt = (name, fallback) => {
    const i = args.indexOf(name);
    return i >= 0 ? args[i + 1] : fallback;
};

function findSkillPlugin() {
    const base = join(
        homedir(),
        '.claude/plugins/cache/superpowers-extended-cc-marketplace/superpowers-extended-cc',
    );
    if (!existsSync(base)) return null;
    const versions = readdirSync(base)
        .filter((v) =>
            existsSync(join(base, v, 'skills/brainstorming/SKILL.md')),
        )
        .sort();
    return versions.length ? join(base, versions[versions.length - 1]) : null;
}

const skillPlugin = opt(
    '--skill-plugin',
    process.env.SKILL_PLUGIN_DIR || findSkillPlugin(),
);
if (
    !skillPlugin ||
    !existsSync(join(skillPlugin, 'skills/brainstorming/SKILL.md'))
) {
    console.error(
        'Cannot find the superpowers-extended-cc plugin. Pass --skill-plugin DIR.',
    );
    process.exit(2);
}
const model = opt(
    '--model',
    process.env.SESSION_TEST_MODEL || 'claude-opus-5-5',
);
const maxCost = opt('--max-cost', '5');
const maxTurns = Number(opt('--max-turns', '8'));
const out = resolve(
    opt('--out', mkdtempSync(join(tmpdir(), `session-test-${kind}-`))),
);
mkdirSync(out, { recursive: true });

// The plugin copy leaves out hooks/, because they call the loupe CLI of the host.
const pluginCopy = join(out, 'plugin-loupe');
rmSync(pluginCopy, { recursive: true, force: true });
cpSync(join(repoRoot, 'plugins/loupe'), pluginCopy, {
    recursive: true,
    filter: (p) => !p.includes('/hooks'),
});

const work = join(out, 'repo');
rmSync(work, { recursive: true, force: true });
mkdirSync(join(work, 'src'), { recursive: true });
writeFileSync(
    join(work, 'README.md'),
    '# Notes app\n\nA small app to write notes.\n',
);
writeFileSync(
    join(work, 'src/notes.py'),
    'notes = []\n\ndef add_note(text):\n    notes.append({"text": text})\n',
);
const git = (...a) => spawnSync('git', a, { cwd: work, encoding: 'utf8' });
git('init', '-q', '-b', 'main');
git('-c', 'user.name=t', '-c', 'user.email=t@t', 'add', '.');
git(
    '-c',
    'user.name=t',
    '-c',
    'user.email=t@t',
    'commit',
    '-q',
    '-m',
    'Initial commit',
);
const baseSha = git('rev-parse', 'HEAD').stdout.trim();

const callLog = join(out, 'loupe-calls.jsonl');
writeFileSync(callLog, '');
const mcpConfig = join(out, 'mcp.json');
writeFileSync(
    mcpConfig,
    JSON.stringify({
        mcpServers: {
            loupe: {
                command: 'node',
                args: [join(here, 'loupe-stub.mjs')],
                env: { LOUPE_STUB_LOG: callLog },
            },
        },
    }),
);

const command =
    kind === 'product' ? '/loupe:product-design' : '/loupe:tech-design';
const first = `${command} 7`;
const replies = [
    'Use the brainstorming skill to lead the interview, not the Loupe method. Owners add free-text tags to a note, many per note. The note list filters by one tag. Keep it small.',
    'Tags are lowercase words, no colors. One owner, so no sharing. Removing a tag from a note is in scope.',
    'Your recommendation is fine for every open point. Please do not ask me for more detail than needed.',
    'Yes, that matches what I want. Go with your recommendations on the rest.',
    'Approved. Write the Loupe document now and finish the session.',
    'Approved. Write the Loupe document now and finish the session.',
    'Approved. Write the Loupe document now and finish the session.',
];

const transcriptPath = join(out, 'transcript.jsonl');
writeFileSync(transcriptPath, '');
const sessionId = randomUUID();
let cost = 0;
const called = () =>
    readFileSync(callLog, 'utf8')
        .split('\n')
        .filter(Boolean)
        .map((l) => JSON.parse(l));

for (let turn = 0; turn < maxTurns; turn++) {
    const prompt =
        turn === 0 ? first : replies[Math.min(turn - 1, replies.length - 1)];
    const cliArgs = [
        '-p',
        prompt,
        turn === 0 ? '--session-id' : '--resume',
        sessionId,
        '--output-format',
        'stream-json',
        '--verbose',
        '--permission-mode',
        'bypassPermissions',
        '--model',
        model,
        '--max-budget-usd',
        maxCost,
        '--plugin-dir',
        pluginCopy,
        '--plugin-dir',
        skillPlugin,
        '--mcp-config',
        mcpConfig,
        '--strict-mcp-config',
        '--setting-sources',
        'project',
    ];
    appendFileSync(
        transcriptPath,
        JSON.stringify({ type: 'owner', turn, text: prompt }) + '\n',
    );
    const r = spawnSync('claude', cliArgs, {
        cwd: work,
        encoding: 'utf8',
        maxBuffer: 256 * 1024 * 1024,
        timeout: 25 * 60 * 1000,
    });
    appendFileSync(transcriptPath, r.stdout || '');
    if (r.stderr)
        appendFileSync(
            join(out, 'stderr.log'),
            `--- turn ${turn}\n${r.stderr}`,
        );
    for (const line of (r.stdout || '').split('\n')) {
        try {
            const e = JSON.parse(line);
            if (e.type === 'result' && e.total_cost_usd)
                cost += e.total_cost_usd;
        } catch {}
    }
    if (r.status !== 0) {
        console.error(`claude exited ${r.status} on turn ${turn}; see ${out}`);
        break;
    }
    if (called().some((c) => c.tool === 'card_run_close')) break;
}

// Checks
const events = readFileSync(transcriptPath, 'utf8')
    .split('\n')
    .filter(Boolean)
    .map((l) => {
        try {
            return JSON.parse(l);
        } catch {
            return null;
        }
    })
    .filter(Boolean);
const toolUses = [];
const walk = (v) => {
    if (Array.isArray(v)) v.forEach(walk);
    else if (v && typeof v === 'object') {
        if (v.type === 'tool_use') toolUses.push(v);
        Object.values(v).forEach(walk);
    }
};
events.filter((e) => e.type === 'assistant').forEach(walk);
const skillCalls = toolUses
    .filter((t) => t.name === 'Skill')
    .map((t) => String(t.input?.skill ?? ''));
const reads = toolUses
    .filter((t) => t.name === 'Read')
    .map((t) => String(t.input?.file_path ?? ''));
const calls = called();

const filesUnder = (d) =>
    existsSync(d) ? readdirSync(d, { recursive: true }) : [];
const specFiles = filesUnder(join(work, 'docs/superpowers'));
const commitCount = Number(git('rev-list', '--count', 'HEAD').stdout.trim());
const porcelain = git('status', '--porcelain').stdout.trim();
const planningLoaded =
    skillCalls.some((s) => /writing-plans/.test(s)) ||
    reads.some((p) => /skills\/writing-plans\//.test(p));
const brainstormingLoaded =
    skillCalls.some((s) => /brainstorming/.test(s)) ||
    reads.some((p) => /skills\/brainstorming\//.test(p));
const creates = calls.filter((c) => c.tool === 'document_create');
const publishes = calls.filter((c) => c.tool === 'document_publish');
const logText = creates.map((c) => JSON.stringify(c.args)).join('\n');
const logNamesSkill =
    /brainstorming/i.test(logText) && /superpowers/i.test(logText);

const checks = [
    [
        'brainstorming skill was loaded (else the test proves nothing)',
        brainstormingLoaded,
    ],
    ['no file under docs/superpowers/', specFiles.length === 0],
    [
        'no new git commit',
        commitCount === 1 && git('rev-parse', 'HEAD').stdout.trim() === baseSha,
    ],
    ['no writing-plans load', !planningLoaded],
    ['document_create called', creates.length > 0],
    ['document_publish not called', publishes.length === 0],
    ['decisions log names the skill and its source', logNamesSkill],
];
const failed = checks.filter(([, ok]) => !ok);
const report = {
    kind,
    model,
    sessionId,
    out,
    cost,
    skillCalls,
    specFiles,
    commitCount,
    untracked: porcelain,
    loupeCalls: calls.map((c) => c.tool),
    checks: Object.fromEntries(checks),
};
writeFileSync(join(out, 'result.json'), JSON.stringify(report, null, 2));
for (const [name, ok] of checks)
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}`);
console.log(`cost: $${cost.toFixed(2)}  transcript: ${transcriptPath}`);
process.exit(failed.length ? 1 : 0);
