<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\RunCommandConfirmation;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RunCommandConfirmationTest extends KernelTestCase
{
    use WorkflowProjects;

    private EntityManagerInterface $em;
    private RunCommandConfirmation $confirmation;
    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private InboxItem $item;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $confirmation = self::getContainer()->get(RunCommandConfirmation::class);
        self::assertInstanceOf(RunCommandConfirmation::class, $confirmation);
        $this->confirmation = $confirmation;

        $this->project = $this->workflowProject('run-command-confirmation');
        $this->bindLifecycle($this->project);
        $createCard = self::getContainer()->get(\App\Module\Board\Command\CreateCardHandler::class);
        $this->card = $createCard(new \App\Module\Board\Command\CreateCardCommand(
            project: $this->project,
            title: 'Add a tag filter',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, 'in-progress'),
            reporter: Actor::Human,
        ));

        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_RUN_COMMAND];
        $this->bridge->projects = [(string) $this->project->id];
        $this->bridge->lastSeenAt = new \DateTimeImmutable();
        $this->bridge->commandEntries = [self::entry('design-export', ['bin/console', 'app:export', '{out}'])];
        $this->bridge->worktrees = [
            ['projectId' => (string) $this->project->id, 'branch' => 'main', 'path' => '/work/loupe'],
            ['projectId' => (string) $this->project->id, 'branch' => 'epic/42', 'path' => '/work/loupe/.worktrees/card-42'],
        ];
        $this->em->persist($this->bridge);

        $this->item = new InboxItem(project: $this->project, number: 1, kind: InboxItemKind::Todo, title: 'Export the design system', blocking: false, origin: InboxAskOrigin::Agent);
        $this->item->suggestedActions = [['action' => 'run-command', 'reason' => 'Export it.', 'recommended' => true, 'cardId' => null, 'command' => ['entry' => 'design-export', 'branch' => 'epic/42', 'params' => ['out' => 'var/out']], 'proposedEntry' => null]];
        $this->item->cards->add(new InboxItemCard($this->item, $this->card));
        $this->em->persist($this->item);
        $this->em->flush();
        $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: $this->bridge->id);
        $ask->items->add(new InboxAskItem($ask, $this->item));
        $this->em->persist($ask);
        $this->em->flush();
    }

    public function test_a_preview_returns_the_view_and_a_digest_that_confirms(): void
    {
        [$view, $digest] = $this->confirmation->preview($this->item, null, null);

        self::assertSame('design-export', $view->entry);
        self::assertSame('epic/42', $view->branch);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
        self::assertSame($view->runDigest(), $this->confirmation->confirm($this->item, null, null, $digest));
    }

    public function test_the_digest_of_the_preview_does_not_cover_another_branch(): void
    {
        [, $digest] = $this->confirmation->preview($this->item, 'epic/42', null);

        $this->assertChanged('main', null, $digest);
    }

    public function test_the_digest_of_the_preview_does_not_cover_other_params(): void
    {
        [, $digest] = $this->confirmation->preview($this->item, null, ['out' => 'one']);

        $this->assertChanged(null, ['out' => 'two'], $digest);
    }

    public function test_the_digest_of_the_preview_does_not_cover_a_changed_run_list(): void
    {
        [, $digest] = $this->confirmation->preview($this->item, null, null);
        $this->bridge->commandEntries = [self::entry('design-export', ['bin/console', 'app:export', '--force', '{out}'])];
        $this->em->flush();

        $this->assertChanged(null, null, $digest);
    }

    public function test_the_digest_of_another_item_does_not_cover_this_one(): void
    {
        [, $digest] = $this->confirmation->preview($this->item, null, null);
        $other = new InboxItem(project: $this->project, number: 2, kind: InboxItemKind::Todo, title: 'Export again', blocking: false, origin: InboxAskOrigin::Agent);
        $other->suggestedActions = $this->item->suggestedActions;
        $other->cards->add(new InboxItemCard($other, $this->card));
        $this->em->persist($other);
        $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: $this->bridge->id);
        $ask->items->add(new InboxAskItem($ask, $other));
        $this->em->persist($ask);
        $this->em->flush();

        try {
            $this->confirmation->confirm($other, null, null, $digest);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['action' => RunInboxActionHandler::COMMAND_CHANGED], $e->errors);
        }
    }

    public function test_a_closed_item_has_no_preview_and_no_confirmation(): void
    {
        [, $digest] = $this->confirmation->preview($this->item, null, null);
        $this->item->state = InboxItemState::Resolved;
        $this->em->flush();

        foreach ([
            fn () => $this->confirmation->preview($this->item, null, null),
            fn () => $this->confirmation->confirm($this->item, null, null, $digest),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a refusal.');
            } catch (DomainErrors $e) {
                self::assertSame(['action' => RunInboxActionHandler::NOT_AVAILABLE], $e->errors);
            }
        }
    }

    public function test_an_item_with_no_command_has_no_preview(): void
    {
        $this->item->suggestedActions = [];
        $this->em->flush();

        $this->expectException(DomainErrors::class);
        $this->confirmation->preview($this->item, null, null);
    }

    /** @param array<string, string>|null $params */
    private function assertChanged(?string $branch, ?array $params, string $digest): void
    {
        try {
            $this->confirmation->confirm($this->item, $branch, $params, $digest);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['action' => RunInboxActionHandler::COMMAND_CHANGED], $e->errors);
        }
    }

    /**
     * @param list<string> $run
     *
     * @return array{name: string, description: string|null, params: list<array{name: string, pattern: string, description: string|null}>, run: list<string>, timeout: string}
     */
    private static function entry(string $name, array $run): array
    {
        return ['name' => $name, 'description' => null, 'params' => [['name' => 'out', 'pattern' => '[a-z/]+', 'description' => null]], 'run' => $run, 'timeout' => '10m'];
    }
}
