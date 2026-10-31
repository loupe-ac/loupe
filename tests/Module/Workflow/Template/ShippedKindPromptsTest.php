<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Workflow\Template\ShippedKindPrompts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ShippedKindPromptsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';

    private const array BRIDGE_PLACEHOLDERS = ['cardId', 'cardNumber', 'projectId', 'project', 'kind', 'ruleId', 'workRequestId', 'subjectType', 'subjectId', 'pullRequestNumber', 'pullRequestUrl', 'headSha', 'reason', 'documentId'];

    public function test_every_work_kind_of_the_lifecycle_template_ships_a_prompt(): void
    {
        $prompts = $this->prompts();

        $kinds = $this->lifecycleKinds();
        self::assertCount(18, $kinds);
        foreach ($kinds as $kind) {
            $text = $prompts->forKind($kind);
            self::assertNotNull($text, \sprintf('The kind "%s" ships no prompt.', $kind));
            self::assertNotSame('', trim($text));
        }
    }

    public function test_a_prompt_names_its_instance_and_only_placeholders_the_bridge_fills(): void
    {
        foreach ($this->lifecycleKinds() as $kind) {
            $text = $this->prompts()->forKind($kind) ?? '';

            self::assertStringContainsString('Loupe instance https://loupe.example.', $text, $kind);
            preg_match_all('/\{([A-Za-z]+)\}/', $text, $found);
            self::assertSame([], array_diff($found[1], self::BRIDGE_PLACEHOLDERS), $kind);
        }
    }

    public function test_a_merge_stage_prompt_asks_for_an_action_that_the_merge_skill_accepts(): void
    {
        $skill = (string) file_get_contents(self::ROOT.'/plugins/loupe/skills/loupe-stage-merge/SKILL.md');

        $checked = 0;
        foreach ($this->lifecycleKinds() as $kind) {
            $lines = explode("\n", $this->prompts()->forKind($kind) ?? '');
            if ('Use the loupe-stage-merge skill.' !== $lines[0]) {
                continue;
            }
            $request = strtr($lines[2], ['{pullRequestUrl}' => '<url>', '{headSha}' => '<sha>']);
            self::assertStringContainsString('The line `'.$request.'`', $skill, $kind);
            ++$checked;
        }
        self::assertSame(5, $checked);
    }

    public function test_a_kind_with_no_prompt_file_or_an_unsafe_name_ships_none(): void
    {
        self::assertNull($this->prompts()->forKind('unknown-kind'));
        self::assertNull($this->prompts()->forKind('../lifecycle'));
        self::assertNull($this->prompts()->forKind(''));
    }

    private function prompts(): ShippedKindPrompts
    {
        return new ShippedKindPrompts(self::ROOT.'/config/workflows/prompts', 'https://loupe.example/');
    }

    /** @return list<string> */
    private function lifecycleKinds(): array
    {
        $template = Yaml::parseFile(self::ROOT.'/config/workflows/lifecycle.yaml');
        $kinds = [(string) $template['onWorkFailed']['repair']['kind']];
        foreach ($template['rules'] as $rule) {
            $then = $rule['then'];
            if (isset($then['request']['kind'])) {
                $kinds[] = (string) $then['request']['kind'];
            }
            if (isset($then['forge-write']['fallback'])) {
                $kinds[] = (string) $then['forge-write']['fallback'];
            }
        }
        $kinds = array_values(array_unique($kinds));
        sort($kinds);

        return $kinds;
    }
}
