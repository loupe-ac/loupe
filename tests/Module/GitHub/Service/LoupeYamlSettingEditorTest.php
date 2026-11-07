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
            "project: abc\n",
            "project: abc\nworkflow:\n  merge-approval: person\n",
        ];
        yield 'no trailing newline in the input' => [
            'project: abc',
            "project: abc\nworkflow:\n  merge-approval: person\n",
        ];
        yield 'a workflow map without the key' => [
            "project: abc\nworkflow:\n  epic-branches: false\n",
            "project: abc\nworkflow:\n  epic-branches: false\n  merge-approval: person\n",
        ];
        yield 'the key set to another value' => [
            "project: abc\nworkflow:\n  merge-approval: agent\n  epic-branches: false\n",
            "project: abc\nworkflow:\n  merge-approval: person\n  epic-branches: false\n",
        ];
        yield 'comments stay' => [
            "# header\nproject: abc # id\nworkflow:\n  # how a merge is approved\n  merge-approval: agent\n\n# footer\n",
            "# header\nproject: abc # id\nworkflow:\n  # how a merge is approved\n  merge-approval: person\n\n# footer\n",
        ];
        yield 'an insert lands before trailing comments and keys' => [
            "project: abc\nworkflow:\n  epic-branches: false\n\n# footer\nother: 1\n",
            "project: abc\nworkflow:\n  epic-branches: false\n  merge-approval: person\n\n# footer\nother: 1\n",
        ];
        yield 'the key in another map is not touched' => [
            "project: abc\nother:\n  merge-approval: agent\n",
            "project: abc\nother:\n  merge-approval: agent\nworkflow:\n  merge-approval: person\n",
        ];
        yield 'the key nested deeper in the workflow map is not touched' => [
            "project: abc\nworkflow:\n  group:\n    merge-approval: agent\n",
            "project: abc\nworkflow:\n  group:\n    merge-approval: agent\n  merge-approval: person\n",
        ];
        yield 'an empty workflow key' => [
            "project: abc\nworkflow:\nother: 1\n",
            "project: abc\nworkflow:\n  merge-approval: person\nother: 1\n",
        ];
        yield 'four space indentation' => [
            "project: abc\nworkflow:\n    epic-branches: false\n",
            "project: abc\nworkflow:\n    epic-branches: false\n    merge-approval: person\n",
        ];
        yield 'windows line endings' => [
            "project: abc\r\nworkflow:\r\n  merge-approval: agent\r\n",
            "project: abc\r\nworkflow:\r\n  merge-approval: person\r\n",
        ];
    }

    #[DataProvider('edits')]
    public function test_it_edits_one_line_and_keeps_the_rest(?string $before, string $after): void
    {
        self::assertSame($after, LoupeYamlSettingEditor::edit($before, 'merge-approval', 'person', self::PROJECT));
    }

    public function test_a_boolean_looking_value_is_accepted(): void
    {
        self::assertSame(
            "project: abc\nworkflow:\n  epic-branches: true\n",
            LoupeYamlSettingEditor::edit("project: abc\nworkflow:\n  epic-branches: false\n", 'epic-branches', 'true', self::PROJECT),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'not yaml' => ["project: abc\nworkflow: [\n", 'file_unparsable'];
        yield 'a list' => ["- a\n- b\n", 'file_unparsable'];
        yield 'a flow map workflow' => ["project: abc\nworkflow: {epic-branches: false}\n", 'file_unparsable'];
        yield 'a workflow that is a string' => ["project: abc\nworkflow: nope\n", 'file_unparsable'];
        yield 'a result over the size cap' => ["project: abc\n# ".str_repeat('x', 70000)."\n", 'file_too_large'];
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
