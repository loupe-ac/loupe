<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Board\Workflow\SetCardValue;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Module\Workflow\Fact\FactsMother;
use App\Tests\Support\DispatchedEvents;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SetCardValueTest extends KernelTestCase
{
    use ActionScenario;

    private const string INTERNAL_KEY = 'session-start-approved';

    public function test_it_writes_an_internal_value_as_the_system_and_dispatches_the_change(): void
    {
        $card = $this->internalCard();
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);

        $outcome = $this->set($card, self::INTERNAL_KEY, true);

        self::assertEquals(ActionOutcome::done(), $outcome);
        $row = $this->service(CardValueRepository::class)->findOneForCardFresh($card, self::INTERNAL_KEY);
        self::assertNotNull($row);
        self::assertTrue($row->value);
        self::assertSame(Actor::System, $row->writerKind);
        self::assertCount(1, $changes->events(), 'The write goes through the card handler, so the card page updates.');
        $events = array_values(array_filter(
            $this->service(CardEventRepository::class)->findForCard($card),
            static fn (CardEvent $event): bool => CardEventKind::ValueChanged === $event->kind,
        ));
        self::assertCount(1, $events);
        self::assertSame(Actor::System, $events[0]->actorKind);
    }

    public function test_it_turns_a_value_off_again(): void
    {
        $card = $this->internalCard();
        $this->set($card, self::INTERNAL_KEY, true);

        self::assertEquals(ActionOutcome::done(), $this->set($card, self::INTERNAL_KEY, false));

        $row = $this->service(CardValueRepository::class)->findOneForCardFresh($card, self::INTERNAL_KEY);
        self::assertNotNull($row);
        self::assertFalse($row->value);
    }

    public function test_a_value_the_card_already_holds_changes_nothing(): void
    {
        $card = $this->internalCard();
        $this->set($card, self::INTERNAL_KEY, true);
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);

        self::assertEquals(ActionOutcome::done(), $this->set($card, self::INTERNAL_KEY, true));

        self::assertSame([], $changes->events());
    }

    public function test_a_key_the_template_does_not_declare_is_refused(): void
    {
        $card = $this->internalCard();

        self::assertEquals(ActionOutcome::refused(SetCardValue::VALUE_REFUSED), $this->set($card, 'ghost', true));
        self::assertNull($this->service(CardValueRepository::class)->findOneForCardFresh($card, 'ghost'));
    }

    private function internalCard(): Card
    {
        self::bootKernel();
        $project = $this->workflowProject('set-value');
        $this->bindLifecycleWithInternalValue($project, self::INTERNAL_KEY);

        return $this->card($project, 'next');
    }

    private function set(Card $card, string $key, bool $value): ActionOutcome
    {
        $rule = $this->rule('set-value', ['key' => $key, 'value' => $value], 'set-the-value');
        $action = new SetCardValue($this->service(CardRepository::class), $this->service(UpdateCardHandler::class));

        return $this->runAction($action, $rule, $card->snapshot(), FactsMother::facts(), $this->state($card, $rule->id));
    }
}
