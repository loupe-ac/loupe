<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\WorkSubject;

use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Project\Entity\Project;
use App\Tests\Support\NoCardTitles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WorkSubjectHandlersTest extends TestCase
{
    public function test_it_finds_the_handler_of_its_subject_type(): void
    {
        $handler = new RecordingWorkSubjectHandler();
        $handlers = new WorkSubjectHandlers([$handler], new NoCardTitles());

        self::assertSame($handler, $handlers->for(RecordingWorkSubjectHandler::TYPE));
        self::assertTrue($handlers->has(RecordingWorkSubjectHandler::TYPE));
    }

    public function test_a_card_has_no_handler_and_is_always_known(): void
    {
        $handlers = new WorkSubjectHandlers([], new NoCardTitles());

        self::assertNull($handlers->for(WorkSubject::CARD));
        self::assertTrue($handlers->has(WorkSubject::CARD));
    }

    public function test_a_type_that_no_module_registers_is_unknown(): void
    {
        $handlers = new WorkSubjectHandlers([new RecordingWorkSubjectHandler()], new NoCardTitles());

        self::assertFalse($handlers->has('report'));
        $this->expectException(\LogicException::class);
        $handlers->for('report');
    }

    public function test_a_card_belongs_to_the_project_that_holds_it(): void
    {
        $mine = $this->createStub(Project::class);
        $theirs = $this->createStub(Project::class);
        $cardId = Uuid::v7();
        $handlers = new WorkSubjectHandlers([], new readonly class($mine, $cardId) implements CardTitleSourceInterface {
            public function __construct(
                private Project $holder,
                private Uuid $cardId,
            ) {
            }

            #[\Override]
            public function titlesFor(Project $project, array $cardIds): array
            {
                return $project === $this->holder && \in_array($this->cardId, $cardIds, true) ? [$this->cardId->toRfc4122() => 'Card'] : [];
            }
        });

        self::assertTrue($handlers->belongsTo($mine, WorkSubject::card($cardId)));
        self::assertFalse($handlers->belongsTo($theirs, WorkSubject::card($cardId)));
        self::assertFalse($handlers->belongsTo($mine, WorkSubject::card(Uuid::v7())));
    }

    public function test_another_subject_asks_its_handler_and_an_unknown_type_belongs_to_no_project(): void
    {
        $handlers = new WorkSubjectHandlers([new RecordingWorkSubjectHandler()], new NoCardTitles());
        $project = $this->createStub(Project::class);
        $id = Uuid::v7();

        self::assertTrue($handlers->belongsTo($project, new WorkSubject(RecordingWorkSubjectHandler::TYPE, $id)));
        self::assertFalse($handlers->belongsTo($project, new WorkSubject('report', $id)));
    }
}
