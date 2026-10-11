<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Artifact\Service\ArtifactLinkedCardsInterface;
use App\Module\Artifact\Service\LinkedCard;
use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Module\Artifact\LinkedCardScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoardArtifactLinkedCardsTest extends KernelTestCase
{
    use ArtifactScenario;
    use LinkedCardScenario;

    public function test_it_counts_the_links_of_each_artifact_and_lists_the_cards_by_number(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), 'linked-cards@example.com', 'Linked');
        $busy = $this->storedArtifact($project, 'Busy', [['index.html' => 'a']]);
        $quiet = $this->storedArtifact($project, 'Quiet', [['index.html' => 'b']]);
        $lonely = $this->storedArtifact($project, 'Lonely', [['index.html' => 'c']]);
        $second = $this->linkedCard($project, $busy, 'Second', 2);
        $first = $this->linkedCard($project, $busy, 'First', 1);
        $this->linkedCard($project, $quiet, 'Other', 3);
        $this->em()->clear();

        $links = self::getContainer()->get(ArtifactLinkedCardsInterface::class);
        self::assertInstanceOf(ArtifactLinkedCardsInterface::class, $links);

        $repository = $this->em()->getRepository($busy::class);
        $artifacts = [$repository->find($busy->id), $repository->find($quiet->id), $repository->find($lonely->id)];
        self::assertEquals([(string) $busy->id => 2, (string) $quiet->id => 1], $links->countFor(array_values(array_filter($artifacts))));
        self::assertSame([], $links->countFor([]));
        self::assertEquals(
            [new LinkedCard((string) $first->id, 1, 'First'), new LinkedCard((string) $second->id, 2, 'Second')],
            $links->cardsOf($artifacts[0] ?? throw new \LogicException('gone')),
        );
        self::assertSame([], $links->cardsOf($artifacts[2] ?? throw new \LogicException('gone')));
    }
}
