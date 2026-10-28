import { spawnSync } from 'node:child_process';
import {
    mkdirSync,
    mkdtempSync,
    realpathSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

const hooks = fileURLToPath(new URL('../../.agents/hooks/', import.meta.url));

function decide(hook, command, cwd, tool = {}, env = {}) {
    const { name, ...input } = tool;
    const run = spawnSync('bash', [hooks + hook], {
        input: JSON.stringify({
            ...(name ? { tool_name: name } : {}),
            tool_input: command === undefined ? input : { command, ...input },
            cwd,
        }),
        encoding: 'utf8',
        env: { ...process.env, ...env },
    });
    expect(run.status).toBe(0);
    if (run.stdout === '') {
        return 'allow';
    }

    return JSON.parse(run.stdout).hookSpecificOutput.permissionDecision;
}

function reason(hook, command, cwd, tool = {}, env = {}) {
    const { name, ...input } = tool;
    const run = spawnSync('bash', [hooks + hook], {
        input: JSON.stringify({
            ...(name ? { tool_name: name } : {}),
            tool_input: command === undefined ? input : { command, ...input },
            cwd,
        }),
        encoding: 'utf8',
        env: { ...process.env, ...env },
    });

    return JSON.parse(run.stdout).hookSpecificOutput.permissionDecisionReason;
}

describe('no-full-ci.sh', () => {
    it.each([
        ['just ci', 'deny'],
        ['just cs ci', 'deny'],
        ['just cs && just ci', 'deny'],
        ['just cs\njust ci', 'deny'],
        ['just --justfile justfile ci', 'deny'],
        ['just -f justfile ci', 'deny'],
        ['just --set x y ci', 'deny'],
        ['FOO=1 just ci', 'deny'],
        ['just ci-report mutation', 'allow'],
        ['just cs', 'allow'],
        ['just lint', 'allow'],
        ['just phpstan', 'allow'],
        ['grep "just ci" AGENTS.md', 'allow'],
        ['echo just ci', 'allow'],
        ['just phpunit', 'deny'],
        ['just phpunit --testsuite tests', 'deny'],
        ['just phpunit tests/Module/Board', 'allow'],
        ['just phpunit tests/', 'deny'],
        ['just phpunit ./tests', 'deny'],
        ['just phpunit-coverage tests', 'deny'],
        ['just exec vendor/bin/phpunit', 'deny'],
        ['just exec env XDEBUG_MODE=off php vendor/bin/phpunit', 'deny'],
        ['just exec vendor/bin/phpunit tests/Module/Board', 'allow'],
        ['just exec bin/console cache:clear', 'allow'],
        ['env XDEBUG_MODE=off vendor/bin/phpunit', 'deny'],
        ['time just ci', 'deny'],
        ['env -u XDEBUG_MODE php vendor/bin/phpunit', 'deny'],
        ['time just phpunit --filter FooTest', 'allow'],
        ['nice -n 10 just ci', 'deny'],
        ['time -p just ci', 'deny'],
        ['timeout 1800 just ci', 'deny'],
        ['( just phpunit )', 'deny'],
        ['( cd .worktrees/x && just ci )', 'deny'],
        ['just phpunit --log-junit tests/results.xml', 'deny'],
        ['just phpunit --log-junit=var/junit.xml tests/Module/Board', 'allow'],
        ['just phpunit --testdox-summary tests/Module/Board', 'allow'],
        ['just phpunit --log-otr tests/report.xml', 'deny'],
        ['just phpunit tests/.', 'deny'],
        ['just phpunit-coverage tests/Module/..', 'deny'],
        ['just phpunit tests/../tests', 'deny'],
        ['just phpunit --coverage-openclover tests/report.xml', 'deny'],
        ['just phpunit --coverage-text tests/Module/Board', 'allow'],
        ['just phpunit --filter FooTest', 'allow'],
        ['just phpunit --filter=FooTest', 'allow'],
        ['just phpunit --group slow', 'allow'],
        ['just cs phpunit', 'deny'],
        ['vendor/bin/phpunit', 'deny'],
        ['./vendor/bin/phpunit', 'deny'],
        ['vendor/bin/phpunit tests/Module/Board/FooTest.php', 'allow'],
        ['php vendor/bin/phpunit', 'deny'],
        ['php vendor/bin/phpunit --filter X', 'allow'],
        [
            'bin/worktrees/compose-exec.sh env XDEBUG_MODE=off vendor/bin/phpunit',
            'deny',
        ],
        [
            'bin/worktrees/compose-exec.sh env XDEBUG_MODE=off vendor/bin/phpunit tests/Foo/BarTest.php',
            'allow',
        ],
        ['./bin/worktrees/compose-exec.sh php vendor/bin/phpunit', 'deny'],
        ['vendor/bin/paratest', 'deny'],
        ['./vendor/bin/paratest --processes=4', 'deny'],
        ['vendor/bin/paratest --processes=4 tests/Module/Board', 'allow'],
        [
            'bin/worktrees/compose-exec.sh env XDEBUG_MODE=off vendor/bin/paratest --processes=4',
            'deny',
        ],
        ['php vendor/bin/paratest --filter FooTest', 'allow'],
        ['just lint && just phpstan && just phpunit', 'deny'],
        ['just phpunit-coverage', 'deny'],
        ['just phpunit-coverage tests/X', 'allow'],
        ['just js-test', 'allow'],
        ['just js-test tests/js/agent_hooks.test.js', 'allow'],
        ["cat > /tmp/x.md <<'EOF'\nRun the gate:\njust ci\nEOF", 'allow'],
        ['cat > x <<EOF\njust phpunit\nEOF', 'allow'],
        ["cat > x <<'EOF'\njust ci\nEOF\ncd x && just ci", 'deny'],
        ['cat <<<"just ci"\njust cs', 'allow'],
        ['cat <<<"x"\njust ci', 'deny'],
        ['cat <<-EOF\n\tjust ci\n\tEOF', 'allow'],
        ['cat <<A <<B\njust ci\nA\njust ci\nB', 'allow'],
        ['cat <<A <<B\njust ci\nA\njust ci\nB\njust ci', 'deny'],
        [`git commit -m "$(cat <<'EOF'\nSay "hi"\njust ci\nEOF\n)"`, 'allow'],
        ['git commit -m "fix\n\njust ci\n\nmore"', 'allow'],
        ['grep "x" f; just ci', 'deny'],
        ['echo "a; just ci"', 'allow'],
        ['bash -c "just ci"', 'deny'],
        ['/bin/bash -c "just ci"', 'deny'],
        ["bash -lc 'cd x && just ci'", 'deny'],
        ['sh -c "echo a\njust ci"', 'deny'],
        ['eval "just ci"', 'deny'],
        ['timeout 60 bash -c "just ci"', 'deny'],
        ['bash -c "eval just ci"', 'deny'],
        ['just exec bash -c "vendor/bin/phpunit"', 'deny'],
        ['bash -c "just phpunit tests/Module/Board"', 'allow'],
        ['git commit -m "bash -c just ci"', 'allow'],
        ['bash script.sh', 'allow'],
        ['just phpunit "tests/Module/Board"', 'allow'],
        ['echo $((1<<2))\njust ci', 'deny'],
        ['(( x = 1 << 2 ))\njust ci', 'deny'],
        ['echo "$((a<<b))"; just ci', 'deny'],
        ['x="$(cat <<EOF\nhi\nEOF)"\njust ci', 'deny'],
        ['x=`cat <<EOF\nhi\nEOF`\njust ci', 'deny'],
        ['echo "$(echo "it\'s")"; just ci', 'deny'],
        ["echo $'it\\'s'; just ci", 'deny'],
        ['echo "a<<b" && just ci', 'deny'],
        ["bash -c 'cat <<EOF\njust ci\nEOF'", 'allow'],
        ["bash -c 'cd x\njust ci'", 'deny'],
        ['echo hi # <<EOF\njust ci', 'deny'],
        ['echo a#b; just ci', 'deny'],
        ['echo ${#x}; just ci', 'deny'],
        ['# just ci', 'allow'],
        ['cat <<EOF\nEOF)\njust ci\nEOF', 'allow'],
        ['x=$(cat <<EOF\nhi\nEOF)\njust ci', 'deny'],
        ["bash -c '' 'just ci'", 'allow'],
        ["bash -c 'just ci' x", 'deny'],
        ["'just ci'", 'allow'],
        ['bash -lc "cd x && just ci"', 'deny'],
        ['eval just ci', 'deny'],
        ['eval "cd x;" "just ci"', 'deny'],
        ['env XDEBUG_MODE="a b" vendor/bin/phpunit', 'deny'],
        ["ju''st ci", 'deny'],
        ['"just" ci', 'deny'],
        ['j"u"st ci', 'deny'],
        ['ju""st phpunit', 'deny'],
        ['echo hi\\\n# <<EOF\njust ci\nEOF', 'allow'],
        ['echo hi \\\n# x\njust ci', 'deny'],
        ['echo $(echo x)#tag; just ci', 'deny'],
        ['echo $((1+2))#x; just ci', 'deny'],
        ['echo "$(echo x)"#y; just ci', 'deny'],
        ['( echo a )# <<EOF\njust ci', 'deny'],
        ['case a in a)# <<EOF\njust ci;; esac', 'deny'],
        ['bash <<EOF\njust ci\nEOF', 'deny'],
        ["sh <<'EOF'\ncd x && just ci\nEOF", 'deny'],
        ['cat <<EOF | bash\njust ci\nEOF', 'deny'],
        ['bash <<EOF\njust cs\nEOF', 'allow'],
        ['cat <<EOF | grep x\njust ci\nEOF', 'allow'],
        ["bash -c 'cat' <<EOF\njust ci\nEOF", 'allow'],
        ['bash -c "echo $(echo hi); just ci"', 'deny'],
        ['x="$(bash <<EOF\njust ci\nEOF\n)"', 'deny'],
        ['cat <<EOF 2>&1 | bash\njust ci\nEOF', 'deny'],
        ['bash <<EOF\necho "x\nEOF\njust ci', 'deny'],
        ['env bash <<EOF\njust ci\nEOF', 'deny'],
        ['timeout 5 bash <<EOF\njust ci\nEOF', 'deny'],
        ['nice -n 5 sh <<EOF\njust ci\nEOF', 'deny'],
        [
            'bin/worktrees/compose-exec.sh bash <<EOF\nvendor/bin/phpunit\nEOF',
            'deny',
        ],
        ['just exec bash <<EOF\nvendor/bin/phpunit\nEOF', 'deny'],
        ['just exec bash <<EOF\njust cs\nEOF', 'allow'],
        ['bash <<OUTER\ncat > README.md <<DOC\njust ci\nDOC\nOUTER', 'allow'],
        ['bash <<OUTER\ncat > x <<DOC\nhi\nDOC\njust ci\nOUTER', 'deny'],
        ['timeout -k 5 60 just ci', 'deny'],
        ['timeout --signal=KILL 60 just ci', 'deny'],
        ["timeout -k 5 60 bash -c 'just ci'", 'deny'],
        ['timeout -s INT 60 bash <<EOF\njust ci\nEOF', 'deny'],
    ])('%j is %s', (command, expected) => {
        expect(decide('no-full-ci.sh', command)).toBe(expected);
    });
});

describe('no-full-e2e.sh', () => {
    it.each([
        ['just e2e', 'deny'],
        ['just e2e tests/board/x.spec.ts', 'allow'],
        ['npx playwright test', 'deny'],
        ['npx playwright test tests/board/x.spec.ts', 'allow'],
        ['just e2e-up', 'allow'],
        ['just e2e-up && just e2e', 'deny'],
        ['just e2e-coverage', 'deny'],
        ['grep "just e2e" AGENTS.md', 'allow'],
        ['cat > x <<EOF\njust e2e\nEOF', 'allow'],
        ["gh pr create --body 'x\njust e2e\ny'", 'allow'],
        ['bash -c "just e2e"', 'deny'],
        ["sh -c 'just e2e-up && just e2e'", 'deny'],
        ['timeout 60 bash -c "just e2e"', 'deny'],
        ['timeout 60 just e2e', 'deny'],
        ['echo hi # <<EOF\njust e2e', 'deny'],
        ["ju''st e2e", 'deny'],
        ['bash <<EOF\njust e2e\nEOF', 'deny'],
        ['bash -c "echo $(echo hi) && just e2e"', 'deny'],
        ["just exec bash -c 'just e2e'", 'deny'],
        ["bin/worktrees/compose-exec.sh bash -c 'just e2e'", 'deny'],
        ["just exec bash -c 'just e2e tests/board/x.spec.ts'", 'allow'],
    ])('%j is %s', (command, expected) => {
        expect(decide('no-full-e2e.sh', command)).toBe(expected);
    });
});

describe('no-unsafe-git.sh', () => {
    it.each([
        ['git add -A', 'deny'],
        ['git add --all', 'deny'],
        ['git add -Av', 'deny'],
        ['git add -fA', 'deny'],
        ['git add .', 'deny'],
        ['git add ./', 'deny'],
        ['git add :/', 'deny'],
        ['git add -- .', 'deny'],
        ['git -C x add -A', 'deny'],
        ['git -c user.name=x add -A', 'deny'],
        ['git --git-dir=x/.git add -A', 'deny'],
        ['git commit -a -m x', 'deny'],
        ['git commit -am x', 'deny'],
        ['git commit --all -m x', 'deny'],
        ['git add -A && git commit -m x', 'deny'],
        ['bash -c "git add -A"', 'deny'],
        ['timeout 60 git add -A', 'deny'],
        ['GIT_TRACE=1 git add -A', 'deny'],
        ['git add src/Foo.php tests/FooTest.php', 'allow'],
        ['git add -u src', 'allow'],
        ['git add -p', 'allow'],
        ['git add src/.', 'allow'],
        ['git add .gitignore', 'allow'],
        ['git commit -m x', 'allow'],
        ['git commit --amend --no-edit', 'allow'],
        ['git commit -m "git add -A"', 'allow'],
        ['echo git add -A', 'allow'],
        ['grep "git add -A" AGENTS.md', 'allow'],
        ['git status', 'allow'],
        ['gh pr ready', 'deny'],
        ['gh pr ready 12', 'deny'],
        ['gh pr ready --repo a/b 12', 'deny'],
        ['gh pr ready --undo 12', 'allow'],
        ['gh pr merge 12 --squash --admin', 'deny'],
        ['gh pr merge --admin 12', 'deny'],
        ['timeout 60 gh pr merge 12 --admin', 'deny'],
        ["sh -c 'gh pr merge 12 --admin'", 'deny'],
        ['gh pr merge 12 --squash', 'allow'],
        ['gh pr create --draft', 'allow'],
        ['gh pr view 12', 'allow'],
        ['echo gh pr ready', 'allow'],
        ['cat > x <<EOF\ngh pr ready\nEOF', 'allow'],
    ])('%j is %s', (command, expected) => {
        expect(decide('no-unsafe-git.sh', command)).toBe(expected);
    });

    describe('docker compose', () => {
        let root;
        let main;
        let worktree;

        beforeAll(() => {
            root = realpathSync(mkdtempSync(join(tmpdir(), 'unsafe-git-')));
            main = join(root, 'main');
            worktree = join(root, 'wt');
            const git = (...args) =>
                spawnSync('git', ['-C', main, ...args], {
                    encoding: 'utf8',
                    env: {
                        ...process.env,
                        GIT_AUTHOR_NAME: 't',
                        GIT_AUTHOR_EMAIL: 't@t',
                        GIT_COMMITTER_NAME: 't',
                        GIT_COMMITTER_EMAIL: 't@t',
                    },
                });
            spawnSync('git', ['init', '-q', main]);
            git('commit', '-q', '--allow-empty', '-m', 'x');
            git('worktree', 'add', '-q', '-b', 'wt', worktree);
        });

        afterAll(() => rmSync(root, { recursive: true, force: true }));

        it.each([
            ['docker compose up -d', 'deny'],
            ['docker compose restart php-fpm', 'deny'],
            ['docker compose down', 'deny'],
            ['docker compose exec php-fpm bash', 'deny'],
            ['docker compose -f compose.yaml -p x up', 'deny'],
            ['docker-compose up', 'deny'],
            ['bash -c "docker compose up"', 'deny'],
            ['just worktree-up && docker compose up', 'deny'],
            ['docker compose ps', 'allow'],
            ['docker compose logs worker', 'allow'],
            ['docker compose -p x logs worker', 'allow'],
            ['docker compose config', 'allow'],
            ['docker compose version', 'allow'],
            ['docker ps', 'allow'],
            ['echo docker compose up', 'allow'],
        ])('%j in a worktree is %s', (command, expected) => {
            expect(decide('no-unsafe-git.sh', command, worktree)).toBe(
                expected,
            );
        });

        it.each([
            ['docker compose up -d', 'main checkout', () => main],
            ['docker compose up -d', 'folder outside git', () => root],
            ['docker compose up -d', 'input with no cwd', () => undefined],
        ])('%j in an %s is allowed', (command, _name, folder) => {
            expect(decide('no-unsafe-git.sh', command, folder())).toBe('allow');
        });
    });
});

describe('no-secret-reads.sh', () => {
    it.each([
        ['cat terraform/terraform.tfvars', 'deny'],
        ['cat ./terraform/terraform.tfvars', 'deny'],
        ['cat /Users/x/Code/loupe/terraform/terraform.tfvars', 'deny'],
        ['cat ../../terraform/terraform.tfvars', 'deny'],
        ['cat "terraform/terraform.tfvars"', 'deny'],
        ['head -5 docker/compose/prod.env', 'deny'],
        ['tail terraform/terraform.tfstate', 'deny'],
        ['tail terraform/terraform.tfstate.backup', 'deny'],
        ['cat terraform/terraform.tfstate.bak', 'deny'],
        ['cat terraform/terraform.tfvars.json', 'deny'],
        ['grep app_secret terraform/terraform.tfvars', 'deny'],
        ['grep -n key docker/compose/prod.env', 'deny'],
        ['sed -n 1,5p terraform/terraform.tfvars', 'deny'],
        ['less terraform/terraform.tfvars', 'deny'],
        ['awk 1 terraform/terraform.tfvars', 'deny'],
        ['cat < terraform/terraform.tfvars', 'deny'],
        ['cat<terraform/terraform.tfvars', 'deny'],
        ['cat $(echo terraform/terraform.tfvars)', 'deny'],
        ['echo $(cat terraform/terraform.tfvars)', 'deny'],
        ['ls $(cat terraform/terraform.tfvars)', 'deny'],
        ['ls > terraform/terraform.tfvars', 'deny'],
        ['cat terraform/.rotate-secrets.tmp.abc123', 'deny'],
        ['cat .rotate-secrets.tmp.x', 'deny'],
        ['f=terraform/terraform.tfvars; cat $f', 'deny'],
        ['F=terraform/terraform.tfvars cat', 'deny'],
        ["sh -c 'cat terraform/terraform.tfvars'", 'deny'],
        ['bash -c "cat docker/compose/prod.env"', 'deny'],
        ["eval 'cat terraform/terraform.tfstate'", 'deny'],
        ['timeout 5 cat terraform/terraform.tfvars', 'deny'],
        ['env X=1 cat terraform/terraform.tfvars', 'deny'],
        ['echo hi && cat terraform/terraform.tfvars', 'deny'],
        ['cp terraform/terraform.tfvars /tmp/x', 'deny'],
        ['cp docker/compose/prod.env /tmp/x', 'deny'],
        ['git add -f terraform/terraform.tfvars', 'deny'],
        ['source docker/compose/prod.env', 'deny'],
        ['. docker/compose/prod.env', 'deny'],
        ['xargs cat terraform/terraform.tfvars', 'deny'],
        ['command cat terraform/terraform.tfvars', 'deny'],
        ['docker compose --env-file docker/compose/prod.env config', 'deny'],
        ['ls terraform/terraform.tfvars', 'allow'],
        ['ls -l terraform/terraform.tfvars docker/compose/prod.env', 'allow'],
        ['stat terraform/terraform.tfvars', 'allow'],
        ['test -f terraform/terraform.tfvars', 'allow'],
        ['[ -f terraform/terraform.tfvars ]', 'allow'],
        ['[[ -e docker/compose/prod.env ]]', 'allow'],
        ['test -f terraform/terraform.tfvars && echo yes', 'allow'],
        ['bin/rotate-secrets', 'allow'],
        ['bin/rotate-secrets app_secret', 'allow'],
        ['./bin/tfvars-names', 'allow'],
        ['bin/tfvars-missing.sh', 'allow'],
        ['bin/tfvars-missing.sh terraform/terraform.tfvars', 'allow'],
        ['bash bin/tfvars-missing.sh terraform/terraform.tfvars', 'allow'],
        ['bin/healthz-probe', 'allow'],
        ['/Users/x/Code/loupe/bin/healthz-probe', 'allow'],
        [
            'ROTATE_TFVARS=terraform/terraform.tfvars bin/rotate-secrets',
            'allow',
        ],
        ['just tfvars-names', 'allow'],
        ['just healthz', 'allow'],
        ['just rotate-secrets', 'allow'],
        ['cat terraform/terraform.tfvars.example', 'allow'],
        ['cat docker/compose/prod.env.example', 'allow'],
        ['cat terraform/variables.tf', 'allow'],
        ['cat docker/compose/prod.yaml', 'allow'],
        ['cat my-terraform.tfvars', 'allow'],
        ['cat .env', 'allow'],
        ['echo terraform', 'allow'],
        ['cat > x <<EOF\nterraform/terraform.tfvars\nEOF', 'allow'],
        ['terraform state pull', 'deny'],
        ['terraform state show digitalocean_app.this', 'deny'],
        ['terraform -chdir=terraform state pull', 'deny'],
        ['cd terraform && terraform state pull', 'deny'],
        ['terraform show -json', 'deny'],
        ['terraform show -json -no-color', 'deny'],
        ['terraform -chdir=terraform show -json', 'deny'],
        ['terraform show -json /tmp/tf.plan', 'allow'],
        ['terraform show /tmp/tf.plan', 'allow'],
        ['terraform state list', 'allow'],
        ['terraform plan -out=/tmp/tf.plan', 'allow'],
        ['terraform output -raw app_id', 'allow'],
        ['echo terraform state pull', 'allow'],
    ])('%j is %s', (command, expected) => {
        expect(decide('no-secret-reads.sh', command)).toBe(expected);
    });

    it('names the safe path in a refusal', () => {
        const text = reason(
            'no-secret-reads.sh',
            'cat terraform/terraform.tfvars',
        );

        expect(text).toContain('just tfvars-names');
        expect(text).toContain('just healthz');
        expect(text).toContain('just rotate-secrets');
        expect(reason('no-secret-reads.sh', 'terraform state pull')).toContain(
            'just rotate-secrets',
        );
    });

    describe('tool inputs', () => {
        it.each([
            ['Read', { file_path: '/a/terraform/terraform.tfvars' }, 'deny'],
            ['Read', { file_path: 'docker/compose/prod.env' }, 'deny'],
            ['Read', { file_path: '/a/terraform/terraform.tfstate' }, 'deny'],
            [
                'Read',
                { file_path: '/a/terraform/.rotate-secrets.tmp.q' },
                'deny',
            ],
            [
                'Read',
                { file_path: '/a/terraform/terraform.tfvars.example' },
                'allow',
            ],
            ['Read', { file_path: '/a/terraform/variables.tf' }, 'allow'],
            ['Edit', { file_path: '/a/terraform/terraform.tfvars' }, 'deny'],
            ['Edit', { file_path: '/a/terraform/main.tf' }, 'allow'],
            ['Write', { file_path: '/a/docker/compose/prod.env' }, 'deny'],
            ['Write', { file_path: '/a/docs/x.md' }, 'allow'],
            ['Grep', { path: '/a/terraform/terraform.tfvars' }, 'deny'],
            ['Grep', { path: '/a/terraform' }, 'allow'],
            ['Grep', { path: '/a/src', pattern: 'terraform.tfvars' }, 'allow'],
            ['Grep', { pattern: 'x' }, 'allow'],
            ['Read', {}, 'allow'],
        ])('%s %j is %s', (name, input, expected) => {
            expect(
                decide('no-secret-reads.sh', undefined, undefined, {
                    name,
                    ...input,
                }),
            ).toBe(expected);
        });

        it.each([
            ['Bash', 'cat terraform/terraform.tfvars', 'deny'],
            ['PowerShell', 'Get-Content terraform\\terraform.tfvars', 'deny'],
            ['PowerShell', 'Get-Content docker\\compose\\prod.env', 'deny'],
            ['PowerShell', 'Test-Path terraform\\terraform.tfvars', 'allow'],
            ['PowerShell', 'Get-Content README.md', 'allow'],
            ['Monitor', 'tail -f terraform/terraform.tfstate', 'deny'],
            ['Monitor', 'tail -f var/log/dev.log', 'allow'],
        ])('%s %j is %s', (name, command, expected) => {
            expect(
                decide('no-secret-reads.sh', command, undefined, { name }),
            ).toBe(expected);
        });
    });

    describe('a glob', () => {
        let folder;

        beforeAll(() => {
            folder = realpathSync(mkdtempSync(join(tmpdir(), 'secret-glob-')));
            writeFileSync(join(folder, '.rotate-secrets.tmp.fixture'), '');
            writeFileSync(join(folder, 'notes.txt'), '');
        });

        afterAll(() => rmSync(folder, { recursive: true, force: true }));

        it.each([
            ['cat .rotate-secrets*', 'deny'],
            ['cat *', 'deny'],
            ['cat .*', 'deny'],
            ['cat ?rotate-secrets.tmp.fixture', 'deny'],
            ['cat .rotate-secrets.tmp.fix?ure', 'deny'],
            ['cat notes*', 'allow'],
            ['cat nothing*', 'allow'],
            ['ls *', 'allow'],
        ])('%j in a folder is %s', (command, expected) => {
            expect(decide('no-secret-reads.sh', command, folder)).toBe(
                expected,
            );
        });
    });

    describe('a search over a folder', () => {
        const place = 'terraform/fixture-secret';
        const env = { NO_SECRET_READS_PLACES: place };
        let root;
        let main;
        let worktree;

        beforeAll(() => {
            root = realpathSync(mkdtempSync(join(tmpdir(), 'secret-search-')));
            main = join(root, 'main');
            worktree = join(root, 'wt');
            const git = (...args) =>
                spawnSync('git', ['-C', main, ...args], {
                    encoding: 'utf8',
                    env: {
                        ...process.env,
                        GIT_AUTHOR_NAME: 't',
                        GIT_AUTHOR_EMAIL: 't@t',
                        GIT_COMMITTER_NAME: 't',
                        GIT_COMMITTER_EMAIL: 't@t',
                    },
                });
            spawnSync('git', ['init', '-q', main]);
            git('commit', '-q', '--allow-empty', '-m', 'x');
            git('worktree', 'add', '-q', '-b', 'wt', worktree);
            for (const folder of ['terraform', 'src']) {
                mkdirSync(join(main, folder));
                mkdirSync(join(worktree, folder));
            }
            writeFileSync(join(main, place), '');
            writeFileSync(join(main, 'README.md'), '');
        });

        afterAll(() => rmSync(root, { recursive: true, force: true }));

        it.each([
            ['grep -rn foo .', 'deny'],
            ['grep -rn foo', 'deny'],
            ['grep -r foo', 'deny'],
            ['grep -R foo terraform', 'deny'],
            ['grep --recursive foo .', 'deny'],
            ['grep -rin foo ./terraform', 'deny'],
            ['grep -e foo -r', 'deny'],
            ['grep foo -r', 'deny'],
            ['egrep -r foo .', 'deny'],
            ['grep -r foo src ..', 'deny'],
            ['grep -r foo src', 'allow'],
            ['grep -r foo README.md', 'allow'],
            ['grep foo README.md', 'allow'],
            ['grep -n foo terraform/main.tf', 'allow'],
            ['rg foo', 'allow'],
            ['rg foo terraform', 'allow'],
            ['rg --no-ignore foo', 'deny'],
            ['rg --no-ignore foo .', 'deny'],
            ['rg -u foo', 'deny'],
            ['rg -uu foo terraform', 'deny'],
            ['rg --no-ignore foo src', 'allow'],
            ['git grep foo', 'allow'],
            ['tar czf /tmp/x.tgz .', 'deny'],
            ['tar -cf /tmp/x.tar terraform', 'deny'],
            ['tar --create --file /tmp/x.tar .', 'deny'],
            ['tar czf /tmp/x.tgz src', 'allow'],
            ['tar xzf /tmp/x.tgz', 'allow'],
            ['tar tzf /tmp/x.tgz', 'allow'],
            ['zip -r /tmp/x.zip .', 'deny'],
            ['zip -r /tmp/x.zip src', 'allow'],
            ['cp -r . /tmp/y', 'deny'],
            ['cp -R terraform /tmp/y', 'deny'],
            ['cp -a . /tmp/y', 'deny'],
            ['cp -r src /tmp/y', 'allow'],
            ['cp README.md /tmp/y', 'allow'],
            ['rsync -a ./ /tmp/y', 'deny'],
            ['rsync -r terraform /tmp/y', 'deny'],
            ['rsync -a src /tmp/y', 'allow'],
            ['bash -c "grep -r foo ."', 'deny'],
            ['cd terraform && grep -r foo .', 'deny'],
            ['grep -r foo ..', 'deny'],
            ['grep -r foo ../..', 'deny'],
        ])('%j in the main checkout is %s', (command, expected) => {
            expect(decide('no-secret-reads.sh', command, main, {}, env)).toBe(
                expected,
            );
        });

        it.each([
            ['grep -rn foo .', 'allow'],
            ['grep -r foo', 'allow'],
            ['grep -r foo ../main', 'deny'],
            ['grep -r foo ../main/terraform', 'deny'],
            ['grep -r foo ..', 'deny'],
            ['grep -r foo ../main/src', 'allow'],
            ['tar czf /tmp/x.tgz ..', 'deny'],
        ])('%j in a worktree is %s', (command, expected) => {
            expect(
                decide('no-secret-reads.sh', command, worktree, {}, env),
            ).toBe(expected);
        });

        it('allows a search in a folder outside git', () => {
            expect(
                decide(
                    'no-secret-reads.sh',
                    'grep -r foo .',
                    tmpdir(),
                    {},
                    env,
                ),
            ).toBe('allow');
        });

        it('names rg and git grep in the refusal', () => {
            const text = reason(
                'no-secret-reads.sh',
                'grep -rn foo .',
                main,
                {},
                env,
            );

            expect(text).toContain('rg');
            expect(text).toContain('git grep');
            expect(text).toContain('just tfvars-names');
        });
    });
});

describe('a 20 KB input', () => {
    it.each([
        [
            'no-full-ci.sh',
            'here-document',
            'cat <<EOF\n' + 'just ci\n'.repeat(2500) + 'EOF',
        ],
        [
            'no-full-e2e.sh',
            'here-document',
            'cat <<EOF\n' + 'just e2e\n'.repeat(2223) + 'EOF',
        ],
        ['no-full-ci.sh', 'lines', 'echo a\n'.repeat(2900)],
        ['no-full-e2e.sh', 'lines', 'echo a\n'.repeat(2900)],
        ['no-full-ci.sh', 'segments', 'echo a; '.repeat(2500)],
        ['no-full-e2e.sh', 'segments', 'echo a; '.repeat(2500)],
        [
            'no-unsafe-git.sh',
            'here-document',
            'cat <<EOF\n' + 'git add -A\n'.repeat(1700) + 'EOF',
        ],
        ['no-unsafe-git.sh', 'lines', 'echo a\n'.repeat(2900)],
        ['no-unsafe-git.sh', 'segments', 'echo a; '.repeat(2500)],
        [
            'no-secret-reads.sh',
            'here-document',
            'cat <<EOF\n' + 'terraform.tfvars\n'.repeat(1200) + 'EOF',
        ],
        ['no-secret-reads.sh', 'lines', 'echo a\n'.repeat(2900)],
        ['no-secret-reads.sh', 'segments', 'echo a; '.repeat(2500)],
    ])('is allowed by %s as %s', (hook, kind, command) => {
        expect(decide(hook, command)).toBe('allow');
    });
});
