<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Service;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Service\ArtifactContentHost;
use App\Module\Artifact\Service\ArtifactContentSigner;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

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
        $other = new ArtifactContentSigner('another-secret', $clock, $this->createStub(UrlGeneratorInterface::class), new ArtifactContentHost(null));

        self::assertNotSame($this->signer($clock)->sign(self::VERSION, 1), $other->sign(self::VERSION, 1));
    }

    public function test_the_label_is_part_of_the_signature(): void
    {
        $clock = new MockClock(self::NOW);
        $signer = $this->signer($clock);
        $expires = $clock->now()->getTimestamp() + 60;
        $signature = $signer->sign(self::VERSION, $expires, 'aaaa');

        self::assertTrue($signer->isValid(self::VERSION, $expires, $signature, 'aaaa'));
        self::assertFalse($signer->isValid(self::VERSION, $expires, $signature, 'bbbb'), 'another sub-address');
        self::assertFalse($signer->isValid(self::VERSION, $expires, $signature), 'the app domain');
        self::assertFalse($signer->isValid(self::VERSION, $expires, $signer->sign(self::VERSION, $expires), 'aaaa'), 'a sealed link on a sub-address');
    }

    public function test_it_builds_the_sealed_path_without_a_content_host(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects($this->once())->method('generate')->with('app_artifact_content')->willReturn('/artifact-content/sealed');
        $signer = new ArtifactContentSigner('a-secret', new MockClock(self::NOW), $urls, new ArtifactContentHost(''));

        self::assertSame('/artifact-content/sealed', $signer->linkFor($this->version(), 'index.html'));
    }

    public function test_it_passes_the_reference_type_to_the_router_without_a_content_host(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects($this->once())->method('generate')
            ->with('app_artifact_content', $this->anything(), UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://app.example/artifact-content/sealed');
        $signer = new ArtifactContentSigner('a-secret', new MockClock(self::NOW), $urls, new ArtifactContentHost(''));

        self::assertSame('https://app.example/artifact-content/sealed', $signer->linkFor($this->version(), 'index.html', UrlGeneratorInterface::ABSOLUTE_URL));
    }

    public function test_it_builds_an_absolute_link_on_the_sub_address_of_the_artifact(): void
    {
        $clock = new MockClock(self::NOW);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects($this->never())->method('generate');
        $host = new ArtifactContentHost('Content.Example');
        $signer = new ArtifactContentSigner('a-secret', $clock, $urls, $host);
        $version = $this->version();
        $label = $host->labelFor($version->artifact);
        $expires = $clock->now()->getTimestamp() + ArtifactContentSigner::TIME_TO_LIVE;
        $versionId = (string) $version->id;

        self::assertSame(
            \sprintf('https://%s.content.example/%s/%d/%s/css/my%%20app.css', $label, $versionId, $expires, $signer->sign($versionId, $expires, $label)),
            $signer->linkFor($version, 'css/my app.css'),
        );
    }

    private function version(): \App\Module\Artifact\Entity\ArtifactVersion
    {
        $project = $this->createStub(Project::class);
        $artifact = new Artifact($project, 'Prototype');
        new \ReflectionProperty(Artifact::class, 'id')->setValue($artifact, Uuid::fromString('0198f3a0-0000-7000-8000-0000000000aa'));
        $version = $artifact->addVersion();
        new \ReflectionProperty(\App\Module\Artifact\Entity\ArtifactVersion::class, 'id')->setValue($version, Uuid::fromString(self::VERSION));

        return $version;
    }

    private function signer(MockClock $clock): ArtifactContentSigner
    {
        return new ArtifactContentSigner('a-secret', $clock, $this->createStub(UrlGeneratorInterface::class), new ArtifactContentHost(null));
    }
}
