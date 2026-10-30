<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardValue;
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
