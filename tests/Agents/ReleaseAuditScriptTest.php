<?php

declare(strict_types=1);

namespace App\Tests\Agents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ReleaseAuditScriptTest extends TestCase
{
    private string $dir;
    private string $repo;
    private string $fixtures;
    private string $baseline;
    private string $script;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/loupe-release-audit-'.bin2hex(random_bytes(6));
        $this->repo = $this->dir.'/repo';
        $this->fixtures = $this->dir.'/fixtures';
        $this->baseline = $this->dir.'/baseline.json';
        mkdir($this->repo, 0o777, true);
        mkdir($this->fixtures, 0o777, true);
        mkdir($this->dir.'/bin', 0o777, true);

        file_put_contents($this->dir.'/bin/gh', <<<'FAKE_WRAP'
        #!/usr/bin/env bash
        if [ "$1" = release ] && [ "$2" = download ]; then
            tag="$3"; dir=""; prev=""; pats=()
            for a in "$@"; do
                [ "$prev" = --dir ] && dir="$a"
                [ "$prev" = --pattern ] && pats+=("$a")
                prev="$a"
            done
            for pat in "${pats[@]}"; do
                src="$FAKE_FIXTURES/assets/${tag//\//__}/$pat"
                [ -f "$src" ] || exit 1
                cp "$src" "$dir/$pat"
            done
            exit 0
        fi
        [ "$1" = api ] || exit 2
        path="$2"
        expr=""
        prev=""
        for a in "$@"; do [ "$prev" = --jq ] && expr="$a"; prev="$a"; done
        file="$FAKE_FIXTURES/${path//\//__}.json"
        if [ ! -f "$file" ]; then
            case "$path" in
                */pulls) file="$FAKE_FIXTURES/default-pulls.json" ;;
                *) exit 1 ;;
            esac
        fi
        jq -c "$expr" "$file"
        FAKE_WRAP);
        chmod($this->dir.'/bin/gh', 0o755);

        $this->script = $this->dir.'/tree/bin/agents/release-audit';
        mkdir(\dirname($this->script), 0o777, true);
        copy(\dirname(__DIR__, 2).'/bin/agents/release-audit', $this->script);
        chmod($this->script, 0o755);
        mkdir($this->dir.'/tree/cli/internal/update', 0o777, true);
        $this->key('good');
        file_put_contents($this->dir.'/tree/cli/internal/update/release_keys', 'loupe-release '.$this->publicKey('good')."\n");
        $this->signRelease('cli/v1.0.0', 'good');

        $this->git($this->dir, 'init', '-q', '--bare', '-b', 'main', 'origin.git');
        $this->git($this->repo, 'init', '-q', '-b', 'main');
        $this->git($this->repo, 'config', 'user.email', 'test@loupe.test');
        $this->git($this->repo, 'config', 'user.name', 'Test');
        $this->git($this->repo, 'config', 'commit.gpgsign', 'false');
        $this->git($this->repo, 'config', 'maintenance.auto', 'false');
        $this->git($this->repo, 'remote', 'add', 'origin', $this->dir.'/origin.git');
        $this->commit('one');
        $this->git($this->repo, 'tag', 'cli/v1.0.0');
        $this->commit('two');
        $this->manifest('loupe', '0.59.7');
        $this->manifest('loupe-lifecycle', '0.4.23');
        $this->git($this->repo, 'add', '.');
        $this->git($this->repo, 'commit', '-q', '-m', 'manifests');
        $this->git($this->repo, 'tag', 'cli/v1.1.0');
        $this->git($this->repo, 'push', '-q', 'origin', 'main');

        $this->fixture('default-pulls', [['merged_at' => '2025-01-05T00:00:00Z', 'base' => ['ref' => 'main']]]);
        $this->fixture('repos/loupe-ac/loupe/releases', [
            ['tag_name' => 'cli/v1.1.0', 'draft' => true, 'created_at' => '2025-01-10T10:00:00Z', 'published_at' => null, 'assets' => []],
            ['tag_name' => 'cli/v1.0.0', 'draft' => false, 'created_at' => '2025-01-01T10:00:00Z', 'published_at' => '2025-01-01T11:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
        ]);
        $this->fixture('repos/loupe-ac/loupe/actions/workflows/cli-release.yml/runs', ['workflow_runs' => [
            ['id' => 2, 'created_at' => '2025-01-10T09:00:00Z', 'conclusion' => 'success'],
            ['id' => 1, 'created_at' => '2025-01-01T09:00:00Z', 'conclusion' => 'success'],
        ]]);
        $this->fixture('repos/loupe-ac/loupe/actions/workflows/skills-release.yml/runs', ['workflow_runs' => []]);
        $this->fixture('repos/loupe-ac/loupe/keys', [['id' => 11, 'created_at' => '2024-01-01T00:00:00Z']]);
        $this->fixture('repos/loupe-ac/loupe/actions/secrets', ['secrets' => [['name' => 'TOKEN', 'updated_at' => '2024-01-01T00:00:00Z']]]);
        $this->fixture('repos/loupe-ac/loupe/environments/homebrew/secrets', ['secrets' => [['name' => 'TAP', 'updated_at' => '2024-01-01T00:00:00Z']]]);
        $this->fixture('repos/loupe-ac/loupe/hooks', []);
        $this->fixture('repos/loupe-ac/loupe/rulesets', [['id' => 5, 'updated_at' => '2024-01-01T00:00:00Z']]);
        $this->fixture('repos/loupe-ac/loupe/environments', ['environments' => [['name' => 'homebrew', 'updated_at' => '2024-01-01T00:00:00Z']]]);
        $this->fixture('repos/loupe-ac/loupe/collaborators', [['login' => 'owner', 'role_name' => 'admin']]);
        $this->fixture('repos/loupe-ac/loupe/invitations', []);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    private const string BASH4_SYNTAX = '/\b(declare|typeset|local|export|readonly)\s+-(?=\w*[^\WafFirxp])\w+|\bmapfile\b|\breadarray\b|\bcoproc\b|\$\{[\w!]+(,|\^|@[UuLlQEPAa])|\|&|&>>|;;?&|\[\[\s+-[vR]\b|\bwait\s+-\w*n|\{[\w-]+\.\.[\w-]+\.\.[\w-]+\}|\bread\s+(-\w+\s+)*-t\s*[0-9]*\.|%\([^)]*\)T|\$BASHPID|\$EPOCH(SECONDS|REALTIME)|\bread\s+(-\w+\s+)*-\w*[iN]\w*\b|\bunset\s+-\w*n|\bshopt\s+(-\w+\s+)*(globstar|lastpipe|autocd|dirspell|checkjobs|direxpand|compat\d+)\b|\bexec\s+\{\w+\}[<>]|\bglobstar\b/';

    public function test_it_runs_on_the_macos_system_bash(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/bin/agents/release-audit');

        self::assertDoesNotMatchRegularExpression(self::BASH4_SYNTAX, $source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bash4Snippets(): iterable
    {
        yield 'fallthrough case' => ['case x in x) true ;& esac'];
        yield 'test-next case' => ['case x in x) true ;;& esac'];
        yield 'associative array' => ['declare -A map'];
        yield 'local nameref' => ['local -n ref=x'];
        yield 'lowercase attribute' => ['declare -l x=ABC'];
        yield 'uppercase attribute' => ['declare -u x=abc'];
        yield 'capitalize attribute' => ['typeset -c x=abc'];
        yield 'global declare' => ['declare -g x=1'];
        yield 'case conversion' => ['echo "${v^^}" "${v,,}" "${v^}" "${v,}"'];
        yield 'transform' => ['echo "${v@U}"'];
        yield 'pipe stderr' => ['a |& b'];
        yield 'append both' => ['a &>> f'];
        yield 'mapfile' => ['mapfile -t lines < f'];
        yield 'variable test' => ['[[ -v name ]]'];
        yield 'wait any' => ['wait -n'];
        yield 'step range' => ['echo {1..10..2}'];
        yield 'fractional read timeout' => ['read -t 0.5 line'];
        yield 'read initial text' => ['read -r -i abc -e line'];
        yield 'read fixed count' => ['read -N 3 line'];
        yield 'unset nameref' => ['unset -n ref'];
        yield 'lastpipe' => ['shopt -s lastpipe'];
        yield 'named fd' => ['exec {fd}>f'];
        yield 'printf time' => ["printf '%(%s)T' -1"];
        yield 'BASHPID' => ['echo $BASHPID'];
    }

    #[DataProvider('bash4Snippets')]
    public function test_the_syntax_check_flags_bash4_forms(string $snippet): void
    {
        self::assertMatchesRegularExpression(self::BASH4_SYNTAX, $snippet);
    }

    public function test_it_reports_no_baseline_then_passes_after_accept(): void
    {
        $first = $this->audit();
        self::assertSame(1, $first->getExitCode(), $first->getOutput());
        self::assertStringContainsString('no baseline', $first->getOutput());
        self::assertFileDoesNotExist($this->baseline);

        $accept = $this->audit('--accept');
        self::assertSame(0, $accept->getExitCode(), $accept->getOutput().$accept->getErrorOutput());
        self::assertFileExists($this->baseline);
        $saved = json_decode((string) file_get_contents($this->baseline), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([11], array_map(intval(...), $saved['deploy_keys']));
        self::assertSame(['owner:admin'], $saved['collaborators']);
        self::assertArrayHasKey('accepted_at', $saved);

        $second = $this->audit();
        self::assertSame(0, $second->getExitCode(), $second->getOutput());
        self::assertStringContainsString('Result: clean.', $second->getOutput());
    }

    public function test_it_stops_on_an_added_and_a_removed_deploy_key(): void
    {
        $this->accept();

        $this->fixture('repos/loupe-ac/loupe/keys', [['id' => 11, 'created_at' => '2024-01-01T00:00:00Z'], ['id' => 12, 'created_at' => '2024-01-01T00:00:00Z']]);
        $added = $this->audit();
        self::assertSame(1, $added->getExitCode());
        self::assertStringContainsString('added [12]', $added->getOutput());

        $this->fixture('repos/loupe-ac/loupe/keys', []);
        $removed = $this->audit();
        self::assertSame(1, $removed->getExitCode());
        self::assertStringContainsString('removed [11]', $removed->getOutput());
    }

    public function test_it_stops_on_a_removed_ruleset_and_a_changed_collaborator_role(): void
    {
        $this->accept();

        $this->fixture('repos/loupe-ac/loupe/rulesets', []);
        $removed = $this->audit();
        self::assertSame(1, $removed->getExitCode());
        self::assertStringContainsString('removed [5]', $removed->getOutput());

        $this->fixture('repos/loupe-ac/loupe/rulesets', [['id' => 5, 'updated_at' => '2024-01-01T00:00:00Z']]);
        $this->fixture('repos/loupe-ac/loupe/collaborators', [['login' => 'owner', 'role_name' => 'write']]);
        $role = $this->audit();
        self::assertSame(1, $role->getExitCode());
        self::assertStringContainsString('added [owner:write]', $role->getOutput());
    }

    public function test_it_stops_on_a_secret_that_changed_after_the_baseline(): void
    {
        $this->accept();

        $this->fixture('repos/loupe-ac/loupe/actions/secrets', ['secrets' => [['name' => 'TOKEN', 'updated_at' => '2999-01-01T00:00:00Z']]]);
        $result = $this->audit();

        self::assertSame(1, $result->getExitCode());
        self::assertStringContainsString('changed after the baseline [TOKEN]', $result->getOutput());
    }

    public function test_it_stops_on_a_commit_with_no_merged_pull_request(): void
    {
        $this->fixture('default-pulls', []);

        $result = $this->audit();

        self::assertSame(1, $result->getExitCode());
        self::assertStringContainsString('with no merged pull request', $result->getOutput());
    }

    public function test_it_stops_on_a_release_that_no_workflow_run_made(): void
    {
        $this->accept();
        $this->fixture('repos/loupe-ac/loupe/releases', [
            ['tag_name' => 'cli/v9.9.9', 'draft' => false, 'created_at' => '2999-01-02T00:00:00Z', 'published_at' => '2999-01-02T00:00:00Z', 'assets' => []],
            ['tag_name' => 'cli/v1.1.0', 'draft' => true, 'created_at' => '2025-01-10T10:00:00Z', 'published_at' => null, 'assets' => []],
            ['tag_name' => 'cli/v1.0.0', 'draft' => false, 'created_at' => '2025-01-01T10:00:00Z', 'published_at' => '2025-01-01T11:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
        ]);

        $result = $this->audit();

        self::assertSame(1, $result->getExitCode());
        self::assertStringContainsString('cli/v9.9.9', $result->getOutput());
    }

    public function test_a_later_signed_cli_release_does_not_move_the_baseline(): void
    {
        $this->accept();
        $this->fixture('repos/loupe-ac/loupe/releases', [
            ['tag_name' => 'cli/v9.9.9', 'draft' => false, 'created_at' => '2999-01-02T00:00:00Z', 'published_at' => '2999-01-02T00:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
            ['tag_name' => 'cli/v1.1.0', 'draft' => true, 'created_at' => '2025-01-10T10:00:00Z', 'published_at' => null, 'assets' => []],
            ['tag_name' => 'cli/v1.0.0', 'draft' => false, 'created_at' => '2025-01-01T10:00:00Z', 'published_at' => '2025-01-01T11:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
        ]);
        $this->fixture('repos/loupe-ac/loupe/actions/workflows/cli-release.yml/runs', ['workflow_runs' => [
            ['id' => 9, 'created_at' => '2999-01-02T00:00:00Z', 'conclusion' => 'success'],
            ['id' => 2, 'created_at' => '2025-01-10T09:00:00Z', 'conclusion' => 'success'],
            ['id' => 1, 'created_at' => '2025-01-01T09:00:00Z', 'conclusion' => 'success'],
        ]]);

        $result = $this->audit();

        self::assertStringContainsString('previous signed CLI release: cli/v1.0.0', $result->getOutput());
    }

    public function test_an_older_release_published_after_the_target_draft_does_not_move_the_baseline(): void
    {
        $this->accept();
        $this->fixture('repos/loupe-ac/loupe/releases', [
            ['tag_name' => 'cli/v1.1.0', 'draft' => true, 'created_at' => '2025-01-10T10:00:00Z', 'published_at' => null, 'assets' => []],
            ['tag_name' => 'cli/v1.0.5', 'draft' => false, 'created_at' => '2025-01-05T10:00:00Z', 'published_at' => '2999-01-02T00:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
            ['tag_name' => 'cli/v1.0.0', 'draft' => false, 'created_at' => '2025-01-01T10:00:00Z', 'published_at' => '2025-01-01T11:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
        ]);
        $this->fixture('repos/loupe-ac/loupe/actions/workflows/cli-release.yml/runs', ['workflow_runs' => [
            ['id' => 2, 'created_at' => '2025-01-10T09:00:00Z', 'conclusion' => 'success'],
            ['id' => 1, 'created_at' => '2025-01-01T09:00:00Z', 'conclusion' => 'success'],
        ]]);

        $result = $this->audit();

        self::assertStringContainsString('previous signed CLI release: cli/v1.0.0', $result->getOutput());
    }

    public function test_a_forged_predecessor_signature_cannot_set_the_baseline(): void
    {
        $this->accept();
        $this->signRelease('cli/v1.0.5', 'forged');
        $this->fixture('repos/loupe-ac/loupe/releases', [
            ['tag_name' => 'cli/v1.1.0', 'draft' => true, 'created_at' => '2025-01-10T10:00:00Z', 'published_at' => null, 'assets' => []],
            ['tag_name' => 'cli/v1.0.5', 'draft' => false, 'created_at' => '2025-01-05T10:00:00Z', 'published_at' => '2025-01-05T11:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
            ['tag_name' => 'cli/v1.0.0', 'draft' => false, 'created_at' => '2025-01-01T10:00:00Z', 'published_at' => '2025-01-01T11:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
        ]);
        $this->fixture('repos/loupe-ac/loupe/actions/workflows/cli-release.yml/runs', ['workflow_runs' => [
            ['id' => 2, 'created_at' => '2025-01-10T09:00:00Z', 'conclusion' => 'success'],
            ['id' => 1, 'created_at' => '2025-01-01T09:00:00Z', 'conclusion' => 'success'],
        ]]);

        $result = $this->audit();

        self::assertSame(1, $result->getExitCode(), $result->getOutput());
        self::assertStringContainsString('previous signed CLI release: cli/v1.0.0', $result->getOutput());
        self::assertStringContainsString('cli/v1.0.5 has a signature asset that no release key signed', $result->getOutput());
    }

    public function test_an_old_draft_published_after_the_baseline_is_a_surprise(): void
    {
        $this->accept();
        $this->fixture('repos/loupe-ac/loupe/releases', [
            ['tag_name' => 'skills/loupe/v9.9.9', 'draft' => false, 'created_at' => '2024-06-01T00:00:00Z', 'published_at' => '2999-01-02T00:00:00Z', 'assets' => []],
            ['tag_name' => 'cli/v1.1.0', 'draft' => true, 'created_at' => '2025-01-10T10:00:00Z', 'published_at' => null, 'assets' => []],
            ['tag_name' => 'cli/v1.0.0', 'draft' => false, 'created_at' => '2025-01-01T10:00:00Z', 'published_at' => '2025-01-01T11:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
        ]);

        $result = $this->audit();

        self::assertSame(1, $result->getExitCode(), $result->getOutput());
        self::assertStringContainsString('3 Releases created after the baseline that this release does not name: skills/loupe/v9.9.9', $result->getOutput());
    }

    public function test_it_stops_on_a_signed_skills_release_that_the_manifests_do_not_name(): void
    {
        $this->accept();
        $this->fixture('repos/loupe-ac/loupe/releases', [
            ['tag_name' => 'skills/loupe/v9.9.9', 'draft' => false, 'created_at' => '2999-01-02T00:00:00Z', 'published_at' => '2999-01-02T00:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
            ['tag_name' => 'cli/v1.1.0', 'draft' => true, 'created_at' => '2025-01-10T10:00:00Z', 'published_at' => null, 'assets' => []],
            ['tag_name' => 'cli/v1.0.0', 'draft' => false, 'created_at' => '2025-01-01T10:00:00Z', 'published_at' => '2025-01-01T11:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
        ]);
        $this->fixture('repos/loupe-ac/loupe/actions/workflows/skills-release.yml/runs', ['workflow_runs' => [
            ['id' => 3, 'created_at' => '2999-01-02T00:00:00Z', 'conclusion' => 'success'],
        ]]);

        $result = $this->audit();

        self::assertSame(1, $result->getExitCode());
        self::assertStringContainsString('skills/loupe/v9.9.9', $result->getOutput());
    }

    public function test_it_accepts_the_skills_releases_that_the_manifests_name(): void
    {
        $this->accept();
        $this->fixture('repos/loupe-ac/loupe/releases', [
            ['tag_name' => 'skills/loupe-lifecycle/v0.4.23', 'draft' => true, 'created_at' => '2999-01-02T00:00:00Z', 'published_at' => null, 'assets' => []],
            ['tag_name' => 'skills/loupe/v0.59.7', 'draft' => true, 'created_at' => '2999-01-02T00:00:00Z', 'published_at' => null, 'assets' => []],
            ['tag_name' => 'cli/v1.1.0', 'draft' => true, 'created_at' => '2025-01-10T10:00:00Z', 'published_at' => null, 'assets' => []],
            ['tag_name' => 'cli/v1.0.0', 'draft' => false, 'created_at' => '2025-01-01T10:00:00Z', 'published_at' => '2025-01-01T11:00:00Z', 'assets' => [['name' => 'checksums.txt.sig']]],
        ]);
        $this->fixture('repos/loupe-ac/loupe/actions/workflows/skills-release.yml/runs', ['workflow_runs' => [
            ['id' => 4, 'created_at' => '2999-01-02T00:00:00Z', 'conclusion' => 'success'],
            ['id' => 3, 'created_at' => '2999-01-02T00:00:00Z', 'conclusion' => 'success'],
        ]]);

        $result = $this->audit();

        self::assertSame(0, $result->getExitCode(), $result->getOutput());
    }

    public function test_it_stops_when_the_tag_is_not_the_deployed_commit(): void
    {
        $result = $this->audit(deployed: str_repeat('a', 40));

        self::assertSame(1, $result->getExitCode());
        self::assertStringContainsString('not at the deployed commit', $result->getOutput());
    }

    public function test_it_exits_two_when_a_check_cannot_read_its_data(): void
    {
        $this->accept();
        unlink($this->fixtures.'/repos__loupe-ac__loupe__keys.json');

        $result = $this->audit();

        self::assertSame(2, $result->getExitCode(), $result->getOutput());
        self::assertStringContainsString('UNREADABLE', $result->getOutput());
    }

    public function test_it_refuses_to_accept_when_a_check_cannot_read_its_data(): void
    {
        unlink($this->fixtures.'/repos__loupe-ac__loupe__invitations.json');

        $result = $this->audit('--accept');

        self::assertSame(2, $result->getExitCode());
        self::assertFileDoesNotExist($this->baseline);
    }

    private function key(string $name): void
    {
        new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $name, '-f', $this->dir.'/'.$name])->mustRun();
    }

    private function publicKey(string $name): string
    {
        $parts = explode(' ', trim((string) file_get_contents($this->dir.'/'.$name.'.pub')));

        return $parts[0].' '.$parts[1];
    }

    private function signRelease(string $tag, string $keyName): void
    {
        if (!is_file($this->dir.'/'.$keyName)) {
            $this->key($keyName);
        }
        $assets = $this->fixtures.'/assets/'.str_replace('/', '__', $tag);
        mkdir($assets, 0o777, true);
        file_put_contents($assets.'/checksums.txt', 'sums '.$tag."\n");
        new Process(['ssh-keygen', '-Y', 'sign', '-f', $this->dir.'/'.$keyName, '-n', 'loupe-release', $assets.'/checksums.txt'])->mustRun();
    }

    private function accept(): void
    {
        self::assertSame(0, $this->audit('--accept')->getExitCode());
    }

    private function audit(string $flag = '', string $deployed = ''): Process
    {
        if ('' === $deployed) {
            $deployed = trim($this->gitOutput($this->repo, 'rev-parse', 'HEAD'));
        }
        $command = [$this->bash(), $this->script, 'cli/v1.1.0', $deployed];
        if ('' !== $flag) {
            $command[] = $flag;
        }
        $process = new Process($command, $this->repo, [
            'PATH' => $this->dir.'/bin:'.getenv('PATH'),
            'FAKE_FIXTURES' => $this->fixtures,
            'RELEASE_AUDIT_FILE' => $this->baseline,
            'HOME' => $this->dir,
        ]);
        $process->run();

        return $process;
    }

    private function bash(): string
    {
        $probe = new Process(['/bin/bash', '-c', 'echo "${BASH_VERSINFO[0]}"']);
        $probe->run();

        return '3' === trim($probe->getOutput()) ? '/bin/bash' : 'bash';
    }

    /**
     * @param array<mixed> $data
     */
    private function fixture(string $path, array $data): void
    {
        file_put_contents(
            $this->fixtures.'/'.str_replace('/', '__', $path).'.json',
            json_encode($data, \JSON_THROW_ON_ERROR),
        );
    }

    private function manifest(string $package, string $version): void
    {
        $dir = $this->repo.'/plugins/'.$package.'/.claude-plugin';
        mkdir($dir, 0o777, true);
        file_put_contents($dir.'/plugin.json', json_encode(['version' => $version], \JSON_THROW_ON_ERROR));
    }

    private function commit(string $name): void
    {
        file_put_contents($this->repo.'/'.$name, $name);
        $this->git($this->repo, 'add', '.');
        $this->git($this->repo, 'commit', '-q', '-m', $name);
    }

    private function git(string $cwd, string ...$args): void
    {
        new Process(['git', ...$args], $cwd)->mustRun();
    }

    private function gitOutput(string $cwd, string ...$args): string
    {
        $process = new Process(['git', ...$args], $cwd);
        $process->mustRun();

        return $process->getOutput();
    }
}
