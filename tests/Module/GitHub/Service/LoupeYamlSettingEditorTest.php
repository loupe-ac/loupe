<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\GitHub\Service\LoupeYamlSettingEditor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoupeYamlSettingEditorTest extends TestCase
{
    private const string PROJECT = '0190a2b4-0000-7000-8000-000000000001';

    /** @return iterable<string, array{?string, string}> */
    public static function edits(): iterable
    {
        yield 'no file' => [null, 'project: '.self::PROJECT."\nworkflow:\n  merge-approval: person\n"];
        yield 'empty file' => ["\n", 'project: '.self::PROJECT."\nworkflow:\n  merge-approval: person\n"];
        yield 'only the project line' => [
            "project: 0190a2b4-0000-7000-8000-000000000001\n",
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  merge-approval: person\n",
        ];
        yield 'no trailing newline in the input' => [
            'project: 0190a2b4-0000-7000-8000-000000000001',
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  merge-approval: person\n",
        ];
        yield 'a workflow map without the key' => [
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  epic-branches: false\n",
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  epic-branches: false\n  merge-approval: person\n",
        ];
        yield 'the key set to another value' => [
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  merge-approval: agent\n  epic-branches: false\n",
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  merge-approval: person\n  epic-branches: false\n",
        ];
        yield 'comments stay' => [
            "# header\nproject: 0190a2b4-0000-7000-8000-000000000001 # id\nworkflow:\n  # how a merge is approved\n  merge-approval: agent\n\n# footer\n",
            "# header\nproject: 0190a2b4-0000-7000-8000-000000000001 # id\nworkflow:\n  # how a merge is approved\n  merge-approval: person\n\n# footer\n",
        ];
        yield 'an insert lands before trailing comments and keys' => [
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  epic-branches: false\n\n# footer\nother: 1\n",
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  epic-branches: false\n  merge-approval: person\n\n# footer\nother: 1\n",
        ];
        yield 'the key in another map is not touched' => [
            "project: 0190a2b4-0000-7000-8000-000000000001\nother:\n  merge-approval: agent\n",
            "project: 0190a2b4-0000-7000-8000-000000000001\nother:\n  merge-approval: agent\nworkflow:\n  merge-approval: person\n",
        ];
        yield 'the key nested deeper in the workflow map is not touched' => [
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  group:\n    merge-approval: agent\n",
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  group:\n    merge-approval: agent\n  merge-approval: person\n",
        ];
        yield 'an empty workflow key' => [
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\nother: 1\n",
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  merge-approval: person\nother: 1\n",
        ];
        yield 'four space indentation' => [
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n    epic-branches: false\n",
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n    epic-branches: false\n    merge-approval: person\n",
        ];
        yield 'windows line endings' => [
            "project: 0190a2b4-0000-7000-8000-000000000001\r\nworkflow:\r\n  merge-approval: agent\r\n",
            "project: 0190a2b4-0000-7000-8000-000000000001\r\nworkflow:\r\n  merge-approval: person\r\n",
        ];
    }

    #[DataProvider('edits')]
    public function test_it_edits_one_line_and_keeps_the_rest(?string $before, string $after): void
    {
        self::assertSame($after, LoupeYamlSettingEditor::edit($before, 'merge-approval', 'person', self::PROJECT));
    }

    public function test_the_project_id_matches_in_any_letter_case(): void
    {
        self::assertSame(
            "project: 0190A2B4-0000-7000-8000-000000000001\nworkflow:\n  merge-approval: person\n",
            LoupeYamlSettingEditor::edit("project: 0190A2B4-0000-7000-8000-000000000001\n", 'merge-approval', 'person', self::PROJECT),
        );
    }

    public function test_a_boolean_looking_value_is_accepted(): void
    {
        self::assertSame(
            "project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  epic-branches: true\n",
            LoupeYamlSettingEditor::edit("project: 0190a2b4-0000-7000-8000-000000000001\nworkflow:\n  epic-branches: false\n", 'epic-branches', 'true', self::PROJECT),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'not yaml' => ["project: 0190a2b4-0000-7000-8000-000000000001\nworkflow: [\n", 'file_unparsable'];
        yield 'a list' => ["- a\n- b\n", 'project_mismatch'];
        yield 'no project key' => ["workflow:\n  epic-branches: false\n", 'project_mismatch'];
        yield 'another project' => ["project: 0190a2b4-0000-7000-8000-000000000002\nworkflow:\n  epic-branches: false\n", 'project_mismatch'];
        yield 'a project key that is not an id' => ["project: abc\n", 'project_mismatch'];
        yield 'a flow map workflow' => ["project: 0190a2b4-0000-7000-8000-000000000001\nworkflow: {epic-branches: false}\n", 'file_unparsable'];
        yield 'a workflow that is a string' => ["project: 0190a2b4-0000-7000-8000-000000000001\nworkflow: nope\n", 'file_unparsable'];
        yield 'a result over the size cap' => ["project: 0190a2b4-0000-7000-8000-000000000001\n# ".str_repeat('x', 70000)."\n", 'file_too_large'];
    }

    #[DataProvider('refused')]
    public function test_it_refuses_a_file_it_cannot_edit_safely(string $content, string $cause): void
    {
        try {
            LoupeYamlSettingEditor::edit($content, 'merge-approval', 'person', self::PROJECT);
        } catch (PullRequestWriteFailed $e) {
            self::assertSame($cause, $e->cause);
            self::assertTrue($e->permanent);

            return;
        }

        self::fail('Expected PullRequestWriteFailed.');
    }

    public function test_it_refuses_a_value_that_could_break_the_line(): void
    {
        $this->expectException(PullRequestWriteFailed::class);

        LoupeYamlSettingEditor::edit(null, 'merge-approval', "person\nevil: 1", self::PROJECT);
    }
}
