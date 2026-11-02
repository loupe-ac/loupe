<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Security\ApiKeyActor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardEventRepositoryApiKeyTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardEventRepository $events;
    private Card $card;
    private User $owner;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $this->events = $events;

        $this->owner = new User(fullName: 'Riley', email: 'event-key-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($this->owner);
        $project = new Project($this->owner, 'event-key-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->card = new Card($project, $this->column($project, 'backlog'), 'A card', '', 1, 'feature');
        $this->em->persist($this->card);
        $this->em->flush();
    }

    public function test_an_api_key_event_keeps_the_key_id_and_the_name_snapshot(): void
    {
        $keyId = Uuid::v7();
        $event = $this->events->record($this->card, CardEventKind::Created, Actor::ApiKey, $this->owner, [], null, new ApiKeyActor($keyId, 'CI'));
        $this->em->flush();
        $eventId = $event->id;
        $this->em->clear();

        $found = $this->em->find(CardEvent::class, $eventId);

        self::assertInstanceOf(CardEvent::class, $found);
        self::assertSame(Actor::ApiKey, $found->actorKind);
        self::assertEquals($keyId, $found->apiKeyId);
        self::assertSame('CI', $found->apiKeyName);
        self::assertSame($this->owner->id?->toRfc4122(), $found->actorUser?->id?->toRfc4122());
    }

    public function test_an_event_of_another_actor_holds_no_key(): void
    {
        $event = $this->events->record($this->card, CardEventKind::Created, Actor::Human, $this->owner, []);
        $this->em->flush();
        $eventId = $event->id;
        $this->em->clear();

        $found = $this->em->find(CardEvent::class, $eventId);

        self::assertInstanceOf(CardEvent::class, $found);
        self::assertNull($found->apiKeyId);
        self::assertNull($found->apiKeyName);
    }
}
