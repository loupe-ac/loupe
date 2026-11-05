<?php

declare(strict_types=1);

namespace App\Tests\Bin;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class InstructionLoadCostTest extends TestCase
{
    private string $dir;

    private string $repo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/load-cost-'.bin2hex(random_bytes(4));
        $this->repo = $this->dir.'/repo';
        $fs = new Filesystem();
        $fs->mkdir([$this->repo.'/bin/agents', $this->dir.'/home']);
        $this->repo = (string) realpath($this->repo);
        $fs->copy(\dirname(__DIR__, 2).'/bin/agents/instruction-load-cost', $this->repo.'/bin/agents/instruction-load-cost');
        chmod($this->repo.'/bin/agents/instruction-load-cost', 0o755);
        $fs->dumpFile($this->repo.'/AGENTS.md', "one two\n");
        $fs->dumpFile($this->repo.'/plugins/loupe/skills/loupe-board/SKILL.md', "---\nname: loupe-board\n---\none two three\n");
        $fs->dumpFile($this->repo.'/plugins/loupe-lifecycle/skills/loupe-stage-review/SKILL.md', "---\nname: loupe-stage-review\n---\none two three\n");
        $fs->dumpFile($this->repo.'/plugins/loupe-lifecycle/skills/loupe-product-design/SKILL.md', "---\nname: \"product-design\"\n---\none two three\n");
        new Process(['git', 'init', '-q'], $this->repo)->mustRun();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function test_every_recorded_skill_name_form_resolves_to_its_skill_file(): void
    {
        $this->transcript([
            'loupe-stage-review',
            'loupe-lifecycle:loupe-stage-review',
            'loupe-lifecycle:product-design',
            'product-design',
            'loupe:loupe-board',
        ]);

        $process = new Process(['bin/agents/instruction-load-cost'], $this->repo, ['HOME' => $this->dir.'/home']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $output = $process->getOutput();
        self::assertMatchesRegularExpression('/^loupe-stage-review +2 +7 +14 /m', $output);
        self::assertMatchesRegularExpression('/^loupe-product-design +2 +7 +14 /m', $output);
        self::assertMatchesRegularExpression('/^loupe-board +1 +7 +7 /m', $output);
        self::assertStringNotContainsString('Not in a skills folder', $output);
    }

    /**
     * @param list<string> $skills
     */
    private function transcript(array $skills): void
    {
        $slug = (string) preg_replace('/[^A-Za-z0-9]/', '-', $this->repo);
        $lines = '';
        foreach ($skills as $i => $skill) {
            $lines .= \sprintf('{"type":"tool_use","id":"toolu_%d","name":"Skill","input":{"skill":"%s"}}', $i, $skill)."\n";
        }
        new Filesystem()->dumpFile($this->dir.'/home/.claude/projects/'.$slug.'/session.jsonl', $lines);
    }
}
