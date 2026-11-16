<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Mercure\LiveUpdateTransactionBuffer;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Artifact\Event\ArtifactChange;
use App\Module\Artifact\Event\ArtifactChanged;
use App\Module\Artifact\EventListener\PublishArtifactChangedOnArtifactChanged;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

final class PublishArtifactChangedOnArtifactChangedTest extends TestCase
{
    /** @var list<Update> */
    private array $updates = [];

    public function test_a_stored_version_signals_the_project_topic_with_ids_and_a_number(): void
    {
        $projectId = Uuid::v7();
        $artifactId = Uuid::v7();
        [$listener, $live] = $this->wired();

        $listener(new ArtifactChanged($projectId, $artifactId, ArtifactChange::Stored, 3));
        self::assertCount(0, $this->sent(), 'Nothing is sent before the publisher flushes.');
        $live->publish();

        self::assertCount(1, $this->sent());
        $update = $this->sent()[0];
        self::assertSame(['https://loupe.example/projects/'.$projectId.'/artifacts'], $update->getTopics());
        self::assertTrue($update->isPrivate());
        self::assertSame(
            ['type' => 'artifact.changed', 'artifactId' => (string) $artifactId, 'kind' => 'stored', 'versionNumber' => 3, 'origin' => null],
            json_decode((string) $update->getData(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function test_an_archive_names_its_kind_and_no_version(): void
    {
        [$listener, $live] = $this->wired();

        $listener(new ArtifactChanged(Uuid::v7(), Uuid::v7(), ArtifactChange::Archived));
        $live->publish();

        self::assertCount(1, $this->sent());
        $data = json_decode($this->sent()[0]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('archived', $data['kind']);
        self::assertNull($data['versionNumber']);
    }

    /** @return list<Update> */
    private function sent(): array
    {
        return $this->updates;
    }

    /** @return array{PublishArtifactChangedOnArtifactChanged, LiveUpdatePublisher} */
    private function wired(): array
    {
        $live = new LiveUpdatePublisher(
            new RequestStack(),
            new LiveUpdateTransactionBuffer(),
            FeatureFlags::service([LiveUpdates::FLAG => true]),
            new NullLogger(),
            fn (): MockHub => new MockHub(
                'http://mercure/.well-known/mercure',
                new StaticTokenProvider('token'),
                function (Update $update): string {
                    $this->updates[] = $update;

                    return 'id';
                },
            ),
        );

        return [new PublishArtifactChangedOnArtifactChanged(new ProjectTopicBuilder('https://loupe.example'), $live), $live];
    }
}
