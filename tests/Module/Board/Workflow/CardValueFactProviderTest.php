<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Board\Workflow\CardValueFactProvider;
use App\Module\Board\Workflow\CardValueFacts;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardValueFactProviderTest extends KernelTestCase
{
    use WorkflowProjects;

    private const string KEY = 'tech-design-interactive';

    public function test_a_card_with_no_row_holds_no_value(): void
    {
        $card = $this->lifecycleCard('value-fact-none');

        self::assertSame([], $this->facts($card)->values);
    }

    public function test_a_stored_value_of_a_declared_key_is_held_also_when_false(): void
    {
        $card = $this->lifecycleCard('value-fact-false');
        $this->em()->persist(new CardValue($card, self::KEY, false, Actor::Human));
        $this->em()->flush();

        self::assertSame([self::KEY => false], $this->facts($card)->values);
    }

    public function test_a_value_of_the_wrong_type_or_an_undeclared_key_reads_as_unset(): void
    {
        $card = $this->lifecycleCard('value-fact-invalid');
        $this->em()->persist(new CardValue($card, self::KEY, 'yes', Actor::Human));
        $this->em()->persist(new CardValue($card, 'gone', true, Actor::Human));
        $this->em()->flush();

        self::assertSame([], $this->facts($card)->values);
    }

    public function test_the_fingerprint_changes_with_any_value(): void
    {
        $card = $this->lifecycleCard('value-fact-print');
        $provider = $this->provider();
        $before = $provider->fingerprint($this->facts($card));
        $this->em()->persist(new CardValue($card, self::KEY, true, Actor::Human));
        $this->em()->flush();
        $after = $provider->fingerprint($this->facts($card));

        self::assertNotSame($before, $after);
        self::assertSame([self::KEY => true], $after);
    }

    public function test_each_held_value_carries_the_time_and_the_kind_of_its_last_write(): void
    {
        $card = $this->lifecycleCard('value-fact-writes');
        $written = new \DateTimeImmutable('2026-10-22 10:00:00.123456');
        $this->em()->persist(new CardValue($card, self::KEY, true, Actor::Agent, null, $written));
        $this->em()->persist(new CardValue($card, 'gone', true, Actor::Human));
        $this->em()->flush();
        $this->em()->clear();

        $writes = $this->facts($card)->writes;

        self::assertSame([self::KEY], array_keys($writes), 'A key the template does not declare has no write.');
        self::assertSame(Actor::Agent, $writes[self::KEY]->writerKind);
        self::assertEquals($written, $writes[self::KEY]->at, 'The write keeps its microseconds.');
    }

    public function test_the_write_stays_out_of_the_fingerprint(): void
    {
        $card = $this->lifecycleCard('value-fact-print-write');
        $provider = $this->provider();
        $this->em()->persist(new CardValue($card, self::KEY, true, Actor::Agent, null, new \DateTimeImmutable('2026-10-22 10:00:00')));
        $this->em()->flush();
        $before = $provider->fingerprint($this->facts($card));
        $cardValues = self::getContainer()->get(CardValueRepository::class);
        self::assertInstanceOf(CardValueRepository::class, $cardValues);
        $row = $cardValues->findOneForCardFresh($card, self::KEY);
        self::assertNotNull($row);
        $row->writerKind = Actor::Human;
        $row->updatedAt = new \DateTimeImmutable('2026-10-23 10:00:00');
        $this->em()->flush();

        self::assertSame($before, $provider->fingerprint($this->facts($card)));
    }

    private function facts(Card $card): CardValueFacts
    {
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(CardValueFacts::class, $facts);

        return $facts;
    }

    private function provider(): CardValueFactProvider
    {
        $provider = self::getContainer()->get(CardValueFactProvider::class);
        self::assertInstanceOf(CardValueFactProvider::class, $provider);

        return $provider;
    }

    private function lifecycleCard(string $slug): Card
    {
        self::bootKernel();
        $project = $this->workflowProject($slug);
        $this->bindLifecycle($project);
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);

        return $create(new CreateCardCommand(
            project: $project,
            title: 'A card',
            body: '',
            type: 'feature',
            column: $this->column($project, 'backlog'),
            reporter: Actor::Agent,
        ));
    }
}
