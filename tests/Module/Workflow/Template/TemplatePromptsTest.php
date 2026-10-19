<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Workflow\Template\TemplatePrompts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplatePromptsTest extends TestCase
{
    public function test_it_reads_the_prompt_file_of_a_template(): void
    {
        $prompts = new TemplatePrompts(TemplateParserTest::PROMPTS_FIXTURE);

        self::assertTrue($prompts->has('test', 'groom-card'));
        self::assertSame("Groom card {cardNumber}.\n", $prompts->read('test', 'groom-card'));
    }

    public function test_a_prompt_of_another_template_is_not_found(): void
    {
        $prompts = new TemplatePrompts(TemplateParserTest::PROMPTS_FIXTURE);

        self::assertFalse($prompts->has('other', 'groom-card'));
        self::assertNull($prompts->read('other', 'groom-card'));
        self::assertNull($prompts->read('test', 'missing'));
    }

    #[DataProvider('unsafeNames')]
    public function test_a_name_that_could_leave_the_folder_is_not_found(string $key, string $name): void
    {
        $prompts = new TemplatePrompts(TemplateParserTest::PROMPTS_FIXTURE);

        self::assertFalse($prompts->has($key, $name));
        self::assertNull($prompts->read($key, $name));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeNames(): iterable
    {
        yield 'a prompt with a path' => ['test', '../groom-card'];
        yield 'a key with a path' => ['../template-prompts/test', 'groom-card'];
        yield 'an empty key' => ['', 'groom-card'];
        yield 'a prompt with a capital' => ['test', 'Groom-card'];
    }
}
