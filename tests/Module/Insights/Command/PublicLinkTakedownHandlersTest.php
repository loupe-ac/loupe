<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Module\Insights\Command\LiftPublicLinkTakedownCommand;
use App\Module\Insights\Command\LiftPublicLinkTakedownHandler;
use App\Module\Insights\Command\ListPublicLinksCommand;
use App\Module\Insights\Command\ListPublicLinksHandler;
use App\Module\Insights\Command\ShowPublicLinkTakedownCommand;
use App\Module\Insights\Command\ShowPublicLinkTakedownHandler;
use App\Module\Insights\Command\TakeDownPublicLinksCommand;
use App\Module\Insights\Command\TakeDownPublicLinksHandler;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicLinkTakedown;
use App\Module\Insights\Repository\PublicLinkTakedownRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublicLinkTakedownHandlersTest extends KernelTestCase
{
    use InsightsScenario;

    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
    }

    public function test_a_takedown_stores_the_row_with_the_admin_and_records_it_once(): void
    {
        $project = $this->scenarioProject('takedown-store');
        $link = $this->sharedLink($project);
        $admin = $this->user($this->em(), 'takedown-admin-'.uniqid().'@example.com');

        $this->takeDown()(new TakeDownPublicLinksCommand($project, $admin));
        $this->takeDown()(new TakeDownPublicLinksCommand($project, $admin));

        $this->em()->clear();
        self::assertSame(1, $this->takedownCount($project));
        $stored = $this->takedownRepository()->findOneBy([]);
        self::assertInstanceOf(PublicLinkTakedown::class, $stored);
        self::assertSame((string) $admin->id, (string) $stored->takenDownBy?->id);
        $record = $this->audit->record('insights.public_links_taken_down');
        self::assertSame((string) $project->id, $record->context['projectId']);
        self::assertSame(1, $record->context['items']);
        self::assertTrue($this->em()->find(PublicLink::class, $link->id)?->enabled);
    }

    public function test_a_lift_deletes_the_row_and_leaves_the_owner_switches_alone(): void
    {
        $project = $this->scenarioProject('takedown-lift');
        $on = $this->sharedLink($project);
        $off = $this->sharedLink($project);
        $off->enabled = false;
        $this->em()->flush();
        $this->seedTakedown($project);
        $this->audit->forget();

        $this->lift()(new LiftPublicLinkTakedownCommand($project));

        $this->em()->clear();
        self::assertSame(0, $this->takedownCount($project));
        self::assertTrue($this->em()->find(PublicLink::class, $on->id)?->enabled);
        self::assertFalse($this->em()->find(PublicLink::class, $off->id)?->enabled);
        self::assertSame(2, $this->audit->record('insights.public_links_restored')->context['items']);
    }

    public function test_a_lift_of_a_project_with_no_takedown_changes_and_records_nothing(): void
    {
        $project = $this->scenarioProject('takedown-lift-none');
        $this->sharedLink($project);
        $this->audit->forget();

        $this->lift()(new LiftPublicLinkTakedownCommand($project));

        self::assertSame([], $this->audit->operations());
    }

    public function test_the_confirm_read_names_the_project_the_owner_and_the_link_count(): void
    {
        $project = $this->scenarioProject('takedown-show');
        $link = $this->sharedLink($project);
        $this->sharedLink($project);

        $view = self::getContainer()->get(ShowPublicLinkTakedownHandler::class);
        self::assertInstanceOf(ShowPublicLinkTakedownHandler::class, $view);
        $detail = $view(new ShowPublicLinkTakedownCommand($link));

        self::assertSame($project->id?->toRfc4122(), $detail->project->id?->toRfc4122());
        self::assertSame($project->owner->email, $detail->owner->email);
        self::assertSame(2, $detail->items);
        self::assertFalse($detail->takenDown);
    }

    public function test_the_list_puts_taken_down_projects_first_and_finds_one_link_by_its_code(): void
    {
        $plain = $this->scenarioProject('takedown-list-a');
        $taken = $this->scenarioProject('takedown-list-z');
        $this->sharedLink($plain);
        $first = $this->sharedLink($taken);
        $second = $this->sharedLink($taken);
        $this->seedTakedown($taken);

        $all = $this->list(new ListPublicLinksCommand(1, 'project', 'asc', userId: (string) $taken->owner->id));
        $both = $this->list(new ListPublicLinksCommand(1, 'project', 'asc', 'takedown-list-'));
        $byCode = $this->list(new ListPublicLinksCommand(1, 'project', 'asc', $second->code));

        self::assertCount(1, $all->rows);
        self::assertSame(2, $all->rows[0]->items);
        self::assertTrue($all->rows[0]->isTakenDown());
        self::assertSame([(string) $taken->id, (string) $plain->id], array_map(static fn ($row): string => $row->projectId, $both->rows));
        self::assertCount(1, $byCode->rows);
        self::assertSame(1, $byCode->rows[0]->items);
        self::assertSame($second->code, $byCode->rows[0]->code);
        self::assertNotSame($first->code, $byCode->rows[0]->code);
        self::assertTrue($byCode->flagOn);
    }

    private function sharedLink(Project $project): PublicLink
    {
        $link = PublicLink::forGraph($this->seedSavedGraph($project, 'Graph '.uniqid()));
        $this->em()->persist($link);
        $this->em()->flush();

        return $link;
    }

    private function takedownCount(Project $project): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_public_link_takedowns WHERE project_id = ?', [(string) $project->id]);
    }

    private function takeDown(): TakeDownPublicLinksHandler
    {
        $handler = self::getContainer()->get(TakeDownPublicLinksHandler::class);
        self::assertInstanceOf(TakeDownPublicLinksHandler::class, $handler);

        return $handler;
    }

    private function lift(): LiftPublicLinkTakedownHandler
    {
        $handler = self::getContainer()->get(LiftPublicLinkTakedownHandler::class);
        self::assertInstanceOf(LiftPublicLinkTakedownHandler::class, $handler);

        return $handler;
    }

    private function list(ListPublicLinksCommand $command): \App\Module\Insights\View\ListPublicLinksView
    {
        $handler = self::getContainer()->get(ListPublicLinksHandler::class);
        self::assertInstanceOf(ListPublicLinksHandler::class, $handler);

        return $handler($command);
    }

    private function takedownRepository(): PublicLinkTakedownRepository
    {
        $repository = self::getContainer()->get(PublicLinkTakedownRepository::class);
        self::assertInstanceOf(PublicLinkTakedownRepository::class, $repository);

        return $repository;
    }
}
