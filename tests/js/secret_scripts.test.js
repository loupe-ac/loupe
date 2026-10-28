import { spawnSync } from 'node:child_process';
import {
    chmodSync,
    mkdtempSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

const bin = fileURLToPath(new URL('../../bin/', import.meta.url));

let dir;

beforeAll(() => {
    dir = mkdtempSync(join(tmpdir(), 'secret-scripts-'));
});

afterAll(() => rmSync(dir, { recursive: true, force: true }));

function stub(name, body) {
    const path = join(dir, 'stubs', name);
    spawnSync('mkdir', ['-p', join(dir, 'stubs')]);
    writeFileSync(path, `#!/usr/bin/env bash\n${body}\n`);
    chmodSync(path, 0o755);
}

describe('bin/tfvars-names', () => {
    const SECRET = 'S3CRET-VALUE-do-not-print';

    function run(env) {
        return spawnSync('bash', [bin + 'tfvars-names'], {
            encoding: 'utf8',
            env: { ...process.env, ...env },
        });
    }

    it('prints names and never values', () => {
        const vars = join(dir, 'names.vars');
        const prod = join(dir, 'names.env');
        writeFileSync(
            vars,
            [
                '# commented = "no"',
                `app_secret = "${SECRET}"`,
                `  indented_name="${SECRET}" # note`,
                'oauth_private_key = <<EOT',
                'MIIEvgIBADANBgkq=',
                'aGVsbG8gd29ybGQ=',
                'EOT',
                `after_heredoc = "${SECRET}"`,
                `x == "${SECRET}"`,
                '',
            ].join('\n'),
        );
        writeFileSync(
            prod,
            `APP_ENV=prod\nDATABASE_URL=${SECRET}\nBAD LINE=${SECRET}\n`,
        );

        const result = run({
            TFVARS_NAMES_TFVARS: vars,
            TFVARS_NAMES_PROD_ENV: prod,
        });

        expect(result.status).toBe(0);
        expect(result.stdout).not.toContain(SECRET);
        expect(result.stderr).not.toContain(SECRET);
        const lines = result.stdout.split('\n');
        for (const name of [
            'app_secret',
            'indented_name',
            'oauth_private_key',
            'after_heredoc',
            'APP_ENV',
            'DATABASE_URL',
        ]) {
            expect(lines).toContain(name);
        }
        expect(result.stdout).not.toContain('MIIEvgIBADANBgkq');
        expect(result.stdout).not.toContain('aGVsbG8');
        expect(result.stdout).not.toContain('commented');
        expect(result.stdout).not.toContain('BAD');
    });

    it('reports a missing file and still exits 0', () => {
        const result = run({
            TFVARS_NAMES_TFVARS: join(dir, 'absent.vars'),
            TFVARS_NAMES_PROD_ENV: join(dir, 'absent.env'),
        });

        expect(result.status).toBe(0);
        expect(result.stdout).toContain('not found');
    });
});

describe('bin/healthz-probe', () => {
    const TOKEN = 'tok3n0123456789abcdef';

    function run(env) {
        stub(
            'terraform',
            `cat > "${dir}/tf.stdin"
printf '%s\\n' "$*" > "${dir}/tf.args"
echo 'Warning: something on stdout'
echo 'Error: health_probe_token = "${TOKEN}"' >&2
if grep -q health_probe_token "${dir}/tf.stdin"; then echo '"${TOKEN}"'; else echo '"app.example.test"'; fi
exit ${env.TF_EXIT ?? 0}`,
        );
        stub(
            'curl',
            `printf '%s\\n' "$*" > "${dir}/curl.args"
cat > "${dir}/curl.stdin"
echo '{"status":"ok","version":"abc1234"}'`,
        );

        return spawnSync('bash', [bin + 'healthz-probe'], {
            encoding: 'utf8',
            env: {
                ...process.env,
                PATH: `${join(dir, 'stubs')}:${process.env.PATH}`,
                HEALTHZ_TF_CHDIR: dir,
            },
        });
    }

    it('sends the token on stdin, prints the body, and never prints the token', () => {
        const result = run({});

        expect(result.status).toBe(0);
        expect(result.stdout).toContain('"version":"abc1234"');
        expect(result.stdout).not.toContain(TOKEN);
        expect(result.stderr).not.toContain(TOKEN);
        const args = readFileSync(join(dir, 'curl.args'), 'utf8');
        expect(args).not.toContain(TOKEN);
        expect(args).toContain('-H @-');
        expect(args).toContain('https://app.example.test/healthz');
        expect(readFileSync(join(dir, 'curl.stdin'), 'utf8')).toBe(
            `X-Probe-Token: ${TOKEN}\n`,
        );
        expect(readFileSync(join(dir, 'tf.args'), 'utf8')).toContain(
            '-no-color',
        );
    });

    it('stops when terraform fails, and never prints its diagnostics', () => {
        const result = run({ TF_EXIT: 1 });

        expect(result.status).not.toBe(0);
        expect(result.stdout).not.toContain(TOKEN);
        expect(result.stderr).not.toContain(TOKEN);
        expect(result.stderr).toContain('cannot read health_probe_token');
    });
});
