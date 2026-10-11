<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service;

use App\Module\Artifact\Service\ArtifactContentSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ArtifactContentSignerTest extends TestCase
{
    private const string VERSION = '0198f3a0-0000-7000-8000-000000000001';
    private const string NOW = '2026-10-11 12:00:00';

    public function test_a_valid_signature_passes_until_the_expiry(): void
    {
        $clock = new MockClock(self::NOW);
        $signer = $this->signer($clock);
        $expires = $clock->now()->getTimestamp() + ArtifactContentSigner::TIME_TO_LIVE;
        $signature = $signer->sign(self::VERSION, $expires);

        self::assertTrue($signer->isValid(self::VERSION, $expires, $signature));

        $clock->sleep(ArtifactContentSigner::TIME_TO_LIVE);
        self::assertTrue($signer->isValid(self::VERSION, $expires, $signature), 'valid at the very second of the expiry');

        $clock->sleep(1);
        self::assertFalse($signer->isValid(self::VERSION, $expires, $signature));
    }

    public function test_a_changed_version_expiry_or_signature_fails(): void
    {
        $clock = new MockClock(self::NOW);
        $signer = $this->signer($clock);
        $expires = $clock->now()->getTimestamp() + 60;
        $signature = $signer->sign(self::VERSION, $expires);

        self::assertFalse($signer->isValid('0198f3a0-0000-7000-8000-000000000002', $expires, $signature), 'wrong version');
        self::assertFalse($signer->isValid(self::VERSION, $expires + 1, $signature), 'stretched expiry');
        self::assertFalse($signer->isValid(self::VERSION, $expires, strrev($signature)), 'tampered signature');
        self::assertFalse($signer->isValid(self::VERSION, $expires, ''), 'empty signature');
    }

    public function test_another_secret_signs_differently(): void
    {
        $clock = new MockClock(self::NOW);
        $other = new ArtifactContentSigner('another-secret', $clock, $this->createStub(UrlGeneratorInterface::class));

        self::assertNotSame($this->signer($clock)->sign(self::VERSION, 1), $other->sign(self::VERSION, 1));
    }

    private function signer(MockClock $clock): ArtifactContentSigner
    {
        return new ArtifactContentSigner('a-secret', $clock, $this->createStub(UrlGeneratorInterface::class));
    }
}
