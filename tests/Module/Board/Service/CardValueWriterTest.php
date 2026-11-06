<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Board\Service\CardValueWriter;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\DispatchedEvents;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CardValueWriterTest extends KernelTestCase
{
    use WorkflowProjects;

    private const string KEY = 'tech-design-interactive';
    private const string INTERNAL_KEY = 'session-start-approved';

    public function test_it_stores_a_value_and_records_the_change(): void
    {
        [$card] = $this->lifecycleCard();

        self::assertTrue($this->writer()->set($card, self::KEY, true, Actor::Human));
        $this->em()->flush();

        $row = $this->row($card);
        self::assertInstanceOf(CardValue::class, $row);
        self::assertTrue($row->value);
        self::assertSame(Actor::Human, $row->writerKind);
        $events = $this->events($card);
        self::assertCount(1, $events);
        self::assertSame(Actor::Human, $events[0]->actorKind);
        self::assertSame(['key' => self::KEY, 'value' => true, 'previous' => null], $events[0]->detail);
    }

    public function test_a_second_write_changes_the_row_and_records_the_previous_value(): void
    {
        [$card] = $this->lifecycleCard();
        $this->writer()->set($card, self::KEY, true, Actor::Human);
        $this->em()->flush();

        self::assertTrue($this->writer()->set($card, self::KEY, false, Actor::Agent));
        $this->em()->flush();

        $row = $this->row($card);
        self::assertInstanceOf(CardValue::class, $row);
        self::assertFalse($row->value);
        self::assertSame(Actor::Agent, $row->writerKind);
        self::assertSame(1, $this->rowCount($card));
        $events = $this->events($card);
        self::assertCount(2, $events);
        self::assertSame(['key' => self::KEY, 'value' => false, 'previous' => true], $events[0]->detail);
    }

    public function test_a_value_the_card_already_holds_changes_nothing(): void
    {
        [$card] = $this->lifecycleCard();
        $this->writer()->set($card, self::KEY, false, Actor::Human);
        $this->em()->flush();
        // Guard: without the first write, the assertions below pass on a writer that never stores.
        self::assertSame(1, $this->rowCount($card));

        self::assertFalse($this->writer()->set($card, self::KEY, false, Actor::Agent));
        $this->em()->flush();

        self::assertCount(1, $this->events($card));
        $row = $this->row($card);
        self::assertInstanceOf(CardValue::class, $row);
        self::assertSame(Actor::Human, $row->writerKind);
    }

    public function test_a_false_value_is_a_value_and_not_an_unset_key(): void
    {
        [$card] = $this->lifecycleCard();

        self::assertTrue($this->writer()->set($card, self::KEY, false, Actor::Human));
        $this->em()->flush();

        self::assertSame(['key' => self::KEY, 'value' => false, 'previous' => null], $this->events($card)[0]->detail);
    }

    public function test_a_stored_value_of_the_wrong_type_reads_as_unset_and_is_replaced(): void
    {
        [$card] = $this->lifecycleCard();
        $this->em()->persist(new CardValue($card, self::KEY, 'yes', Actor::Human));
        $this->em()->flush();

        self::assertTrue($this->writer()->set($card, self::KEY, false, Actor::Human));
        $this->em()->flush();

        $row = $this->row($card);
        self::assertInstanceOf(CardValue::class, $row);
        self::assertFalse($row->value);
        self::assertSame(['key' => self::KEY, 'value' => false, 'previous' => null], $this->events($card)[0]->detail);
    }

    public function test_it_refuses_a_value_of_the_wrong_type(): void
    {
        [$card] = $this->lifecycleCard();

        try {
            $this->writer()->set($card, self::KEY, 'yes', Actor::Human);
            self::fail('The value was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['values.'.self::KEY => 'board.card.error.value_type_bool'], $e->errors);
            self::assertSame('This key takes true or false.', $this->translate($e, 'values.'.self::KEY));
        }
        self::assertSame(0, $this->rowCount($card));
    }

    public function test_it_refuses_an_undeclared_key_and_lists_the_declared_ones(): void
    {
        [$card] = $this->lifecycleCard();

        try {
            $this->writer()->set($card, 'nope', true, Actor::Human);
            self::fail('The key was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame(
                'The workflow template declares no key nope. Declared keys: '.self::KEY.'.',
                $this->translate($e, 'values.nope'),
            );
        }
    }

    public function test_it_says_none_when_the_template_declares_no_key(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('values-none');
        $card = $this->card($project);

        try {
            $this->writer()->set($card, self::KEY, true, Actor::Human);
            self::fail('The key was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame(
                'The workflow template declares no key '.self::KEY.'. Declared keys: none.',
                $this->translate($e, 'values.'.self::KEY),
            );
        }
    }

    public function test_a_refusal_lists_every_bad_entry(): void
    {
        [$card] = $this->lifecycleCard();

        $refusal = $this->writer()->refusal($card, ['nope' => true, self::KEY => 'yes'], Actor::Human);

        self::assertInstanceOf(DomainErrors::class, $refusal);
        self::assertSame(['values.nope', 'values.'.self::KEY], array_keys($refusal->errors));
        self::assertNull($this->writer()->refusal($card, [self::KEY => true], Actor::Human));
    }

    /** @return iterable<string, array{Actor}> */
    public static function callersThatAreNotTheWorkflow(): iterable
    {
        yield 'a person' => [Actor::Human];
        yield 'an agent' => [Actor::Agent];
        yield 'an api key' => [Actor::ApiKey];
    }

    #[DataProvider('callersThatAreNotTheWorkflow')]
    public function test_an_internal_key_is_refused_for_every_caller_but_the_workflow(Actor $actor): void
    {
        [$card] = $this->internalCard();

        $refusal = $this->writer()->refusal($card, [self::INTERNAL_KEY => true], $actor);
        self::assertInstanceOf(DomainErrors::class, $refusal);
        self::assertSame(CardValueWriter::INTERNAL_KEY, $refusal->errors['values.'.self::INTERNAL_KEY]);
        try {
            $this->writer()->set($card, self::INTERNAL_KEY, true, $actor);
            self::fail('The key was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame('The key '.self::INTERNAL_KEY.' is internal. Only the workflow writes it.', $this->translate($e, 'values.'.self::INTERNAL_KEY));
        }
        $this->em()->flush();
        self::assertSame(0, $this->rowCount($card, self::INTERNAL_KEY));
        self::assertSame([], $this->events($card));
    }

    public function test_the_workflow_writes_an_internal_key_and_the_row_names_the_system(): void
    {
        [$card] = $this->internalCard();
        $writer = $this->writer();

        self::assertNull($writer->refusal($card, [self::INTERNAL_KEY => true], Actor::System));
        self::assertTrue($writer->set($card, self::INTERNAL_KEY, true, Actor::System));
        $this->em()->flush();

        self::assertSame(1, $this->rowCount($card, self::INTERNAL_KEY));
        $events = $this->events($card);
        self::assertCount(1, $events);
        self::assertSame(Actor::System, $events[0]->actorKind);
    }

    public function test_the_workflow_still_gets_the_refusals_of_an_undeclared_key_and_a_wrong_type(): void
    {
        [$card] = $this->internalCard();

        $refusal = $this->writer()->refusal($card, ['nope' => true, self::INTERNAL_KEY => 'yes'], Actor::System);

        self::assertInstanceOf(DomainErrors::class, $refusal);
        self::assertSame(['values.nope', 'values.'.self::INTERNAL_KEY], array_keys($refusal->errors));
        self::assertSame('board.card.error.value_type_bool', $refusal->errors['values.'.self::INTERNAL_KEY]);
    }

    public function test_the_declared_keys_in_an_undeclared_key_error_leave_out_the_internal_ones(): void
    {
        [$card] = $this->internalCard();

        $refusal = $this->writer()->refusal($card, ['nope' => true], Actor::Human);

        self::assertInstanceOf(DomainErrors::class, $refusal);
        self::assertSame(['%key%' => 'nope', '%declared%' => self::KEY], $refusal->parameters['values.nope']);
    }

    public function test_the_handler_refuses_an_internal_key_from_a_person_and_writes_it_for_the_system(): void
    {
        [$card] = $this->internalCard();
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);

        try {
            ($this->update())(new UpdateCardCommand($card, Actor::Human, title: 'Renamed', values: [self::KEY => true, self::INTERNAL_KEY => true]));
            self::fail('The update was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['values.'.self::INTERNAL_KEY], array_keys($e->errors));
        }
        self::assertSame('A card', $card->title);
        self::assertSame(0, $this->rowCount($card, self::KEY));
        self::assertSame([], $changes->events());

        ($this->update())(new UpdateCardCommand($card, Actor::System, values: [self::INTERNAL_KEY => true]));

        self::assertSame(1, $this->rowCount($card, self::INTERNAL_KEY));
        self::assertCount(1, $changes->events());
    }

    public function test_a_card_value_keeps_the_microseconds_of_its_write(): void
    {
        [$card] = $this->internalCard();
        $this->writer()->set($card, self::INTERNAL_KEY, true, Actor::System);
        $this->em()->flush();
        $this->em()->clear();

        $stored = $this->em()->getConnection()->fetchOne('SELECT updated_at FROM board_card_values WHERE card_id = :id', ['id' => (string) $card->id]);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{1,6}$/', (string) $stored, 'A whole second would mean the column dropped the microseconds.');
    }

    public function test_the_handler_writes_the_values_and_dispatches_one_card_change(): void
    {
        [$card] = $this->lifecycleCard();
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);

        ($this->update())(new UpdateCardCommand($card, Actor::Human, values: [self::KEY => true]));

        $row = $this->row($card);
        self::assertInstanceOf(CardValue::class, $row);
        self::assertTrue($row->value);
        self::assertCount(1, $changes->events());
        self::assertCount(1, $this->events($card));
    }

    public function test_the_handler_dispatches_nothing_when_the_value_is_unchanged(): void
    {
        [$card] = $this->lifecycleCard();
        ($this->update())(new UpdateCardCommand($card, Actor::Human, values: [self::KEY => true]));
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);

        ($this->update())(new UpdateCardCommand($card, Actor::Human, values: [self::KEY => true]));

        self::assertSame([], $changes->events());
        self::assertCount(1, $this->events($card));
    }

    public function test_the_handler_refuses_a_bad_entry_and_writes_none_of_the_others(): void
    {
        [$card] = $this->lifecycleCard();
        $changes = DispatchedEvents::of(self::getContainer(), CardChanged::class);

        try {
            ($this->update())(new UpdateCardCommand($card, Actor::Human, title: 'Renamed', values: [self::KEY => true, 'nope' => true]));
            self::fail('The update was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['values.nope'], array_keys($e->errors));
            self::assertSame(['%key%' => 'nope', '%declared%' => self::KEY], $e->parameters['values.nope']);
        }

        self::assertSame(0, $this->rowCount($card));
        self::assertSame([], $this->events($card));
        self::assertSame('A card', $card->title);
        self::assertSame([], $changes->events());
    }

    public function test_a_command_with_no_values_leaves_them_alone(): void
    {
        [$card] = $this->lifecycleCard();
        ($this->update())(new UpdateCardCommand($card, Actor::Human, values: [self::KEY => true]));

        ($this->update())(new UpdateCardCommand($card, Actor::Human, title: 'Renamed'));

        $row = $this->row($card);
        self::assertInstanceOf(CardValue::class, $row);
        self::assertTrue($row->value);
    }

    /** @return array{0: Card, 1: Project} */
    private function lifecycleCard(): array
    {
        self::bootKernel();
        $project = $this->workflowProject('values-writer');
        $this->bindLifecycle($project);

        return [$this->card($project), $project];
    }

    /** @return array{0: Card, 1: Project} */
    private function internalCard(): array
    {
        self::bootKernel();
        $project = $this->workflowProject('values-internal');
        $this->bindLifecycleWithInternalValue($project, self::INTERNAL_KEY);

        return [$this->card($project), $project];
    }

    private function card(Project $project): Card
    {
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

    private function writer(): CardValueWriter
    {
        $writer = self::getContainer()->get(CardValueWriter::class);
        self::assertInstanceOf(CardValueWriter::class, $writer);

        return $writer;
    }

    private function update(): UpdateCardHandler
    {
        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);

        return $handler;
    }

    private function row(Card $card): ?CardValue
    {
        $repository = self::getContainer()->get(CardValueRepository::class);
        self::assertInstanceOf(CardValueRepository::class, $repository);

        return $repository->findOneForCardFresh($card, self::KEY);
    }

    private function rowCount(Card $card, ?string $key = null): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM board_card_values WHERE card_id = :id AND (CAST(:key AS VARCHAR) IS NULL OR key = :key)',
            ['id' => (string) $card->id, 'key' => $key],
        );
    }

    /** @return list<CardEvent> newest first */
    private function events(Card $card): array
    {
        $repository = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $repository);

        return array_values(array_filter(
            $repository->findForCard($card),
            static fn (CardEvent $event): bool => CardEventKind::ValueChanged === $event->kind,
        ));
    }

    private function translate(DomainErrors $e, string $field): string
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator->trans($e->errors[$field], $e->parameters[$field] ?? []);
    }
}
