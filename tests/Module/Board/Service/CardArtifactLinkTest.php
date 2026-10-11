<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Artifact\Command\ArtifactFileInput;
use App\Module\Artifact\Command\StoreArtifactVersionCommand;
use App\Module\Artifact\Command\StoreArtifactVersionHandler;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectDeleter;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardArtifactLinkTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;
    private StoreArtifactVersionHandler $store;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $create = $container->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $this->createCard = $create;
        $update = $container->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $this->updateCard = $update;
        $store = $container->get(StoreArtifactVersionHandler::class);
        self::assertInstanceOf(StoreArtifactVersionHandler::class, $store);
        $this->store = $store;
        $this->em->getConnection()->executeStatement("UPDATE feature_flag SET value = 'true' WHERE name = ?", [ArtifactInstallFlags::FLAG_ENABLED]);
    }

    public function test_a_card_is_created_with_the_artifacts_it_names(): void
    {
        $project = $this->project('create');
        $artifact = $this->artifact($project, 'Widget tokens');

        $card = ($this->createCard)($this->newCard($project, [(string) $artifact->id]));

        self::assertSame([(string) $artifact->id], $this->linkedIds($card));
    }

    public function test_an_artifact_of_another_project_or_no_artifact_is_refused(): void
    {
        $project = $this->project('mine');
        $theirs = $this->artifact($this->project('theirs'), 'Theirs');

        foreach ([(string) $theirs->id, 'not-a-uuid', '0199c0de-0000-7000-8000-0000000000ff'] as $id) {
            try {
                ($this->createCard)($this->newCard($project, [$id]));
                self::fail(\sprintf('Expected "%s" to be refused.', $id));
            } catch (DomainErrors $e) {
                self::assertSame(['artifactIds' => 'board.card.error.artifact_unknown'], $e->errors);
            }
        }
    }

    public function test_an_omitted_list_keeps_the_links_an_empty_one_clears_them_and_a_list_replaces(): void
    {
        $project = $this->project('replace');
        $first = $this->artifact($project, 'First');
        $second = $this->artifact($project, 'Second');
        $card = ($this->createCard)($this->newCard($project, [(string) $first->id]));

        ($this->updateCard)(new UpdateCardCommand($card, Actor::Agent, title: 'Renamed'));
        self::assertSame([(string) $first->id], $this->linkedIds($card));

        ($this->updateCard)(new UpdateCardCommand($card, Actor::Agent, artifactIds: [(string) $first->id, (string) $second->id]));
        self::assertSame([(string) $first->id, (string) $second->id], $this->linkedIds($card));

        ($this->updateCard)(new UpdateCardCommand($card, Actor::Agent, artifactIds: [(string) $second->id]));
        self::assertSame([(string) $second->id], $this->linkedIds($card));

        ($this->updateCard)(new UpdateCardCommand($card, Actor::Agent, artifactIds: []));
        self::assertSame([], $this->linkedIds($card));
    }

    public function test_an_update_that_names_an_unknown_artifact_changes_nothing(): void
    {
        $project = $this->project('update-refused');
        $artifact = $this->artifact($project, 'Kept');
        $card = ($this->createCard)($this->newCard($project, [(string) $artifact->id]));

        try {
            ($this->updateCard)(new UpdateCardCommand($card, Actor::Agent, title: 'Never applied', artifactIds: ['0199c0de-0000-7000-8000-0000000000ff']));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertArrayHasKey('artifactIds', $e->errors);
        }

        self::assertSame([(string) $artifact->id], $this->linkedIds($card));
        self::assertNotSame('Never applied', $card->title);
    }

    public function test_a_push_that_names_a_card_by_number_links_it_and_a_second_push_adds_no_row(): void
    {
        $project = $this->project('push-number');
        $card = ($this->createCard)($this->newCard($project, []));

        $first = $this->push($project, card: (string) $card->number, name: 'Prototype');
        $this->push($project, card: (string) $card->number, artifactId: (string) $first->artifact->id);

        $this->em->clear();
        $reloaded = $this->em->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $reloaded);
        self::assertSame([(string) $first->artifact->id], $this->linkedIds($reloaded));
        self::assertSame(2, $first->artifact->versions->count());
    }

    public function test_a_push_that_names_a_card_by_id_links_it(): void
    {
        $project = $this->project('push-id');
        $card = ($this->createCard)($this->newCard($project, []));

        $version = $this->push($project, card: (string) $card->id, name: 'Prototype');

        self::assertSame([(string) $version->artifact->id], $this->linkedIds($card));
    }

    public function test_a_push_without_a_card_links_nothing(): void
    {
        $project = $this->project('push-bare');
        $card = ($this->createCard)($this->newCard($project, []));

        $this->push($project, name: 'Prototype');

        self::assertSame([], $this->linkedIds($card));
    }

    public function test_a_push_naming_a_card_of_another_project_is_refused_and_stores_nothing(): void
    {
        $project = $this->project('push-mine');
        $foreign = ($this->createCard)($this->newCard($this->project('push-theirs'), []));
        $before = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM artifacts');

        foreach ([(string) $foreign->id, (string) $foreign->number, 'nope'] as $reference) {
            try {
                $this->push($project, card: (string) $reference, name: 'Prototype');
                self::fail(\sprintf('Expected "%s" to be refused.', $reference));
            } catch (DomainErrors $e) {
                self::assertSame(['card' => 'artifact.error.card_not_found'], $e->errors);
            }
        }

        self::assertSame($before, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM artifacts'));
    }

    public function test_deleting_the_project_removes_the_links(): void
    {
        $project = $this->project('cascade');
        $artifact = $this->artifact($project, 'Gone');
        ($this->createCard)($this->newCard($project, [(string) $artifact->id]));
        $conn = $this->em->getConnection();
        // Guard: the absence below also passes on a fixture that never wrote a link.
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_artifacts'));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($project);

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_artifacts'));
    }

    private function project(string $slug): Project
    {
        $owner = new User(fullName: 'Riley', email: $slug.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug.'-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        return $project;
    }

    private function artifact(Project $project, string $name): Artifact
    {
        return $this->push($project, name: $name)->artifact;
    }

    private function push(Project $project, ?string $card = null, ?string $name = null, ?string $artifactId = null): \App\Module\Artifact\Entity\ArtifactVersion
    {
        return ($this->store)(new StoreArtifactVersionCommand(
            $project->owner,
            (string) $project->id,
            $artifactId,
            $name,
            null,
            null,
            [new ArtifactFileInput('index.html', uniqid(), 'text/html')],
            $card,
        ));
    }

    /** @param list<string> $artifactIds */
    private function newCard(Project $project, array $artifactIds): CreateCardCommand
    {
        return new CreateCardCommand(
            project: $project,
            title: 'Prototype the screen',
            body: 'body',
            type: 'feature',
            artifactIds: $artifactIds,
        );
    }

    /** @return list<string> */
    private function linkedIds(Card $card): array
    {
        return array_map(
            static fn ($link): string => (string) $link->artifact->id,
            array_values($card->artifacts->toArray()),
        );
    }
}
