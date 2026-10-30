<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Board\Service\CardValueReader;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardValueKeys;
use App\Module\Workflow\Contract\CardValueType;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardValueReaderTest extends KernelTestCase
{
    use WorkflowProjects;

    private const string KEY = 'tech-design-interactive';

    public function test_an_unset_key_reads_with_no_value(): void
    {
        $card = $this->lifecycleCard();

        $views = $this->reader()->forCard($card);

        self::assertCount(1, $views);
        self::assertSame(self::KEY, $views[0]->key);
        self::assertSame(CardValueType::Bool, $views[0]->type);
        self::assertSame('workflow.value.tech_design_interactive.label', $views[0]->label);
        self::assertSame('workflow.value.tech_design_interactive.caption', $views[0]->caption);
        self::assertSame('workflow.value.tech_design_interactive.help', $views[0]->help);
        self::assertSame('workflow.value.tech_design_interactive.history', $views[0]->history);
        self::assertNull($views[0]->value);
        self::assertNull($views[0]->writerKind);
        self::assertNull($views[0]->writerUser);
        self::assertNull($views[0]->updatedAt);
    }

    public function test_a_stored_value_reads_with_its_writer_and_time(): void
    {
        $card = $this->lifecycleCard();
        $updatedAt = new \DateTimeImmutable('2026-03-05 14:00:00');
        $this->em()->persist(new CardValue($card, self::KEY, false, Actor::Agent, null, $updatedAt));
        $this->em()->flush();

        $view = $this->reader()->forCard($card)[0];

        // A stored false is a value, so it differs from an unset key.
        self::assertFalse($view->value);
        self::assertSame(Actor::Agent, $view->writerKind);
        self::assertEquals($updatedAt, $view->updatedAt);
    }

    public function test_a_value_of_another_type_reads_as_unset(): void
    {
        $card = $this->lifecycleCard();
        $this->em()->persist(new CardValue($card, self::KEY, 'yes', Actor::Human));
        $this->em()->flush();

        $view = $this->reader()->forCard($card)[0];

        self::assertNull($view->value);
        self::assertNull($view->writerKind);
        self::assertNull($view->updatedAt);
    }

    public function test_a_row_of_an_undeclared_key_is_left_out(): void
    {
        $card = $this->lifecycleCard();
        $this->em()->persist(new CardValue($card, 'gone', true, Actor::Human));
        $this->em()->flush();

        self::assertSame([self::KEY], array_map(static fn ($view): string => $view->key, $this->reader()->forCard($card)));
    }

    public function test_a_project_whose_template_declares_no_key_reads_nothing(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('values-reader-none');
        $card = $this->card($project);
        $this->em()->persist(new CardValue($card, self::KEY, true, Actor::Human));
        $this->em()->flush();

        self::assertSame([], $this->reader()->forCard($card));
    }

    private function lifecycleCard(): Card
    {
        self::bootKernel();
        $project = $this->workflowProject('values-reader');
        $this->bindLifecycle($project);

        return $this->card($project);
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

    private function reader(): CardValueReader
    {
        $keys = self::getContainer()->get(CardValueKeys::class);
        self::assertInstanceOf(CardValueKeys::class, $keys);
        $values = self::getContainer()->get(CardValueRepository::class);
        self::assertInstanceOf(CardValueRepository::class, $values);

        return new CardValueReader($keys, $values);
    }
}
