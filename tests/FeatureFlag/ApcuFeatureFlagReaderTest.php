<?php

declare(strict_types=1);

namespace App\Tests\FeatureFlag;

use App\FeatureFlag\ApcuFeatureFlagReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\Dto\ResolvedFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;

final class ApcuFeatureFlagReaderTest extends TestCase
{
    private FeatureFlagReaderInterface&MockObject $inner;

    public function test_outside_prod_it_reads_the_inner_reader_once_per_request(): void
    {
        $this->inner = $this->createMock(FeatureFlagReaderInterface::class);
        $flag = new ResolvedFlag('a.flag', FeatureFlagType::Bool, true);
        $this->inner->expects(self::once())->method('all')->willReturn(['a.flag' => $flag]);

        $reader = new ApcuFeatureFlagReader($this->inner, 'test');

        self::assertSame($flag, $reader->get('a.flag'));
        self::assertNull($reader->get('missing'));
        self::assertSame(['a.flag' => $flag], $reader->all());
    }

    public function test_reset_makes_the_next_read_go_to_the_inner_reader_again(): void
    {
        $this->inner = $this->createMock(FeatureFlagReaderInterface::class);
        $this->inner->expects(self::exactly(2))->method('all')->willReturn([]);

        $reader = new ApcuFeatureFlagReader($this->inner, 'test');
        $reader->all();
        $reader->reset();
        $reader->all();
    }

    public function test_the_lifetime_is_sixty_seconds(): void
    {
        self::assertSame(60, ApcuFeatureFlagReader::TTL_SECONDS);
    }
}
