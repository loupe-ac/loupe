<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\AddFeedbackCommand;
use App\Module\Board\Command\AddFeedbackHandler;
use App\Module\Board\Service\BoardStartColumnProvider;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\NewAnchor;
use App\Module\SiteReview\StartColumn\StartColumn;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AddFeedbackStartColumnTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;
    private AddFeedbackHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('feedback-start');
        $this->bindLifecycle($this->project);
        $handler = self::getContainer()->get(AddFeedbackHandler::class);
        self::assertInstanceOf(AddFeedbackHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_the_list_holds_backlog_first_and_the_columns_a_reviewer_may_start(): void
    {
        $provider = self::getContainer()->get(BoardStartColumnProvider::class);
        self::assertInstanceOf(BoardStartColumnProvider::class, $provider);

        $slugs = array_map(
            fn (StartColumn $column): string => $this->slugOf($column->id),
            $provider->forCurrentRequest($this->project),
        );

        self::assertSame(['backlog', 'next', 'product-design', 'tech-design'], $slugs);
    }

    public function test_a_column_the_workflow_allows_starts_the_card_there(): void
    {
        $result = ($this->handler)($this->command($this->column($this->project, 'tech-design')->id));

        self::assertNull($result->startRefused);
        self::assertSame('tech-design', $result->link->card->column->slug);
    }

    public function test_a_column_the_workflow_does_not_allow_keeps_the_card_in_backlog(): void
    {
        $result = ($this->handler)($this->command($this->column($this->project, 'in-progress')->id));

        self::assertSame('In progress', $result->startRefused);
        self::assertSame('backlog', $result->link->card->column->slug);
    }

    public function test_the_terminal_column_is_refused(): void
    {
        $result = ($this->handler)($this->command($this->column($this->project, 'done')->id));

        self::assertNotNull($result->startRefused);
        self::assertSame('backlog', $result->link->card->column->slug);
    }

    public function test_a_note_on_an_existing_card_ignores_the_start_column(): void
    {
        $first = ($this->handler)($this->command(null))->link->card;

        $second = ($this->handler)($this->command($this->column($this->project, 'tech-design')->id, (string) $first->id));

        self::assertNull($second->startRefused);
        self::assertSame('backlog', $second->link->card->column->slug);
    }

    private function command(?Uuid $startColumnId, ?string $cardId = null): AddFeedbackCommand
    {
        return new AddFeedbackCommand(
            project: $this->project,
            body: 'Footer overlaps the launcher',
            url: 'https://preview.example/checkout',
            anchors: [new NewAnchor('.footer', 'Footer')],
            cardId: $cardId,
            startColumnId: null === $startColumnId ? null : (string) $startColumnId,
        );
    }

    private function slugOf(string $columnId): string
    {
        foreach (['backlog', 'next', 'product-design', 'tech-design', 'in-progress', 'in-review', 'done'] as $slug) {
            if ((string) $this->column($this->project, $slug)->id === $columnId) {
                return $slug;
            }
        }

        throw new \LogicException('unknown column '.$columnId);
    }
}
