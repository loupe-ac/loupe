<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service;

use App\Module\Artifact\Service\ArtifactSourceKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArtifactSourceKindTest extends TestCase
{
    /** @return iterable<string, array{?string, ?ArtifactSourceKind}> */
    public static function links(): iterable
    {
        yield 'no link' => [null, null];
        yield 'blank link' => ['  ', null];
        yield 'design canvas' => ['https://claude.ai/design/p/76e0?file=Options.dc.html', ArtifactSourceKind::ClaudeDesign];
        yield 'claude.ai artifact' => ['https://claude.ai/artifact/BQMw6RNMErZGuaxNtGk8GF', ArtifactSourceKind::ClaudeArtifact];
        yield 'claude.ai public artifact' => ['https://claude.ai/public/artifacts/abc', ArtifactSourceKind::ClaudeArtifact];
        yield 'claude.site' => ['https://claude.site/artifacts/abc', ArtifactSourceKind::ClaudeArtifact];
        yield 'other site' => ['https://design.example/canvas/1', ArtifactSourceKind::Link];
        yield 'other claude.ai page' => ['https://claude.ai/chat/abc', ArtifactSourceKind::Link];
        yield 'not a url' => ['javascript:alert(1)', ArtifactSourceKind::Link];
    }

    #[DataProvider('links')]
    public function test_it_reads_the_tool_from_the_link(?string $link, ?ArtifactSourceKind $expected): void
    {
        self::assertSame($expected, ArtifactSourceKind::fromLink($link));
    }
}
