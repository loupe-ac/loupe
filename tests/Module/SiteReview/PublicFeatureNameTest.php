<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicFeatureNameTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function publicFiles(): iterable
    {
        yield 'marketplace' => ['.claude-plugin/marketplace.json'];
        yield 'plugin' => ['plugins/loupe/.claude-plugin/plugin.json'];
        yield 'docs readme' => ['website/README.md'];
        yield 'explainer' => ['public/landing/explainer.html'];
        foreach (glob(dirname(__DIR__, 3).'/plugins/loupe/skills/*/SKILL.md') ?: [] as $skill) {
            $relative = substr($skill, strlen(dirname(__DIR__, 3)) + 1);
            yield $relative => [$relative];
        }
    }

    #[DataProvider('publicFiles')]
    public function test_public_text_names_the_feature_live_review(string $file): void
    {
        $text = (string) file_get_contents(dirname(__DIR__, 3).'/'.$file);
        self::assertNotSame('', $text);

        // Identifiers keep the old spelling: the skill name, the token scope, class names and env vars.
        $prose = preg_replace('/`[^`]*`|loupe-site-review|lp-site-review[\w-]*|SITE_REVIEW\w*/', '', $text);

        self::assertDoesNotMatchRegularExpression('/\bsite[ -]review\b/i', (string) $prose);
    }
}
