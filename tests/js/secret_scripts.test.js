import { spawnSync } from 'node:child_process';
import {
    chmodSync,
    existsSync,
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
const fakeSops = fileURLToPath(
    new URL('../Bin/fixtures/fake-sops', import.meta.url),
);

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

    it('lists the names the secret store holds and never a value', () => {
        const store = join(dir, 'names.store.yaml');
        writeFileSync(
            store,
            'TF_VAR_app_secret: ENC[AES256_GCM,data:S3CRET-VALUE-do-not-print]\nsops:\n  version: 3\n',
        );

        const result = run({
            TFVARS_NAMES_TFVARS: join(dir, 'absent.vars'),
            TFVARS_NAMES_PROD_ENV: join(dir, 'absent.env'),
            TF_SECRETS_STORE: store,
        });

        expect(result.status).toBe(0);
        expect(result.stdout).toContain('== secret store\nTF_VAR_app_secret\n');
        expect(result.stdout).not.toContain('S3CRET');
        expect(result.stdout).not.toContain('sops');
    });

    it('says so when no store is set up', () => {
        const result = run({
            TFVARS_NAMES_TFVARS: join(dir, 'absent.vars'),
            TFVARS_NAMES_PROD_ENV: join(dir, 'absent.env'),
            TF_SECRETS_STORE: join(dir, 'no-such-store.yaml'),
        });

        expect(result.status).toBe(0);
        expect(result.stdout).toContain('(no store set up)');
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

    // The terraform stub answers the token from TF_VAR_health_probe_token when it is set, as Terraform does.
    function run(env, command = [bin + 'healthz-probe']) {
        rmSync(join(dir, 'curl.args'), { force: true });
        stub(
            'terraform',
            `cat > "${dir}/tf.stdin"
printf '%s\\n' "$*" > "${dir}/tf.args"
echo 'Warning: something on stdout'
echo 'Error: health_probe_token = "${TOKEN}"' >&2
if grep -q health_probe_token "${dir}/tf.stdin"; then echo "\\"\${TF_VAR_health_probe_token:-${TOKEN}}\\""; else echo '"${env.DOMAIN ?? 'app.example.test'}"'; fi
exit ${env.TF_EXIT ?? 0}`,
        );
        stub(
            'curl',
            `printf '%s\\n' "$*" > "${dir}/curl.args"
cat > "${dir}/curl.stdin"
echo '{"status":"ok","version":"abc1234"}'`,
        );

        return spawnSync('bash', command, {
            encoding: 'utf8',
            env: {
                ...process.env,
                PATH: `${join(dir, 'stubs')}:${process.env.PATH}`,
                HEALTHZ_TF_CHDIR: dir,
                ...env.extra,
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

    it('stops before curl when custom_domain is empty', () => {
        const result = run({ DOMAIN: '' });

        expect(result.status).not.toBe(0);
        expect(result.stderr).toContain('custom_domain is empty or unreadable');
        expect(existsSync(join(dir, 'curl.args'))).toBe(false);
        expect(result.stdout + result.stderr).not.toContain(TOKEN);
    });

    it('runs as just healthz does, with the token from the secret store', () => {
        const recipe = readFileSync(
            fileURLToPath(new URL('../../justfile', import.meta.url)),
            'utf8',
        );
        expect(recipe).toContain(
            'healthz:\n    bin/tf-secrets exec -- bin/healthz-probe\n',
        );
        const stored = 'st0red0123456789abcdef';
        const store = join(dir, 'probe.store.yaml');
        writeFileSync(store, `TF_VAR_health_probe_token: "${stored}"\n`);
        spawnSync('cp', [fakeSops, join(dir, 'stubs', 'sops')]);

        const result = run({ extra: { TF_SECRETS_STORE: store } }, [
            bin + 'tf-secrets',
            'exec',
            '--',
            bin + 'healthz-probe',
        ]);

        expect(result.status).toBe(0);
        expect(result.stdout).toContain('"version":"abc1234"');
        expect(readFileSync(join(dir, 'curl.stdin'), 'utf8')).toBe(
            `X-Probe-Token: ${stored}\n`,
        );
        expect(readFileSync(join(dir, 'curl.args'), 'utf8')).not.toContain(
            stored,
        );
        expect(result.stdout + result.stderr).not.toContain(stored);
    });

    it('stops before curl under the store when custom_domain is empty', () => {
        const store = join(dir, 'probe.store.yaml');
        writeFileSync(store, 'TF_VAR_health_probe_token: "st0red-token"\n');
        spawnSync('cp', [fakeSops, join(dir, 'stubs', 'sops')]);

        const result = run({ DOMAIN: '', extra: { TF_SECRETS_STORE: store } }, [
            bin + 'tf-secrets',
            'exec',
            '--',
            bin + 'healthz-probe',
        ]);

        expect(result.status).not.toBe(0);
        expect(result.stderr).toContain('custom_domain is empty or unreadable');
        expect(existsSync(join(dir, 'curl.args'))).toBe(false);
        expect(result.stdout + result.stderr).not.toContain('st0red-token');
    });
});

describe('bin/tf-secrets exec', () => {
    it('runs the command unchanged when no store is set up', () => {
        const result = spawnSync(
            'bash',
            [bin + 'tf-secrets', 'exec', '--', 'sh', '-c', 'printf %s "$X"'],
            {
                encoding: 'utf8',
                env: {
                    ...process.env,
                    X: 'plain',
                    TF_SECRETS_STORE: join(dir, 'no-such-store.yaml'),
                },
            },
        );

        expect(result.status).toBe(0);
        expect(result.stdout).toBe('plain');
        expect(result.stderr).toContain('no secret store is set up');
    });

    it('refuses a call with no command', () => {
        const result = spawnSync('bash', [bin + 'tf-secrets', 'exec'], {
            encoding: 'utf8',
            env: { ...process.env, TF_SECRETS_STORE: join(dir, 'none.yaml') },
        });

        expect(result.status).toBe(2);
    });
});
