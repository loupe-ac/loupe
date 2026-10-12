<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service;

use App\Module\Artifact\Service\ArtifactContentHost;
use PHPUnit\Framework\TestCase;

final class ArtifactContentHostTest extends TestCase
{
    private const string LABEL = '0198f3a0000070008000000000000001';

    public function test_an_empty_or_missing_value_means_no_content_domain(): void
    {
        foreach ([null, '', '  '] as $value) {
            $host = new ArtifactContentHost($value);

            self::assertNull($host->host);
            self::assertNull($host->labelOfRequestHost(self::LABEL.'.content.example'));
            self::assertFalse($host->ownsHost('content.example'));
            self::assertNull($host->originFor(self::LABEL));
        }
    }

    public function test_it_reads_the_label_of_a_sub_address_and_nothing_else(): void
    {
        $host = new ArtifactContentHost('Content.Example');

        self::assertSame('content.example', $host->host);
        self::assertSame(self::LABEL, $host->labelOfRequestHost(strtoupper(self::LABEL).'.content.example'));
        self::assertNull($host->labelOfRequestHost('content.example'));
        self::assertNull($host->labelOfRequestHost('www.content.example'));
        self::assertNull($host->labelOfRequestHost(self::LABEL.'.other.example'));
        self::assertNull($host->labelOfRequestHost('x.'.self::LABEL.'.content.example'));
        self::assertSame('https://'.self::LABEL.'.content.example', $host->originFor(self::LABEL));
    }

    public function test_it_owns_the_domain_and_every_sub_address_of_it(): void
    {
        $host = new ArtifactContentHost('content.example');

        self::assertTrue($host->ownsHost('content.example'));
        self::assertTrue($host->ownsHost('anything.content.example'));
        self::assertFalse($host->ownsHost('evilcontent.example'));
        self::assertFalse($host->ownsHost('loupe.dev.localhost'));
    }

    public function test_the_label_is_the_artifact_id_without_hyphens(): void
    {
        self::assertSame(self::LABEL, new ArtifactContentHost(null)->labelOfId('0198F3A0-0000-7000-8000-000000000001'));
    }
}
