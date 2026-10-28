<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Reference;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Reference\ReferenceLinker;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReferenceLinkerTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private ReferenceLinker $linker;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $linker = self::getContainer()->get(ReferenceLinker::class);
        self::assertInstanceOf(ReferenceLinker::class, $linker);
        $this->linker = $linker;
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'linker-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'linker-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        return $project;
    }

    private function card(Project $project, string $title = 'Gate new work'): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand($project, $title, 'Body', 'feature'));
    }

    private function watch(Project $project, string $path): ForgeRepository
    {
        $repository = new ForgeRepository($project, 'github', 'ext-'.uniqid(), $path, ForgeRepositorySource::Hook);
        $this->em->persist($repository);
        $this->em->flush();

        return $repository;
    }

    private function pullRequest(Project $project, string $path, int $number, ?string $title, PullRequestState $state = PullRequestState::Open, bool $draft = false): void
    {
        $row = new ForgePullRequest($project, 'github', $path, $number);
        $row->title = $title;
        $row->state = $state;
        $row->draft = $draft;
        $this->em->persist($row);
        $this->em->flush();
    }

    private function region(string $inner): string
    {
        return '<div data-references>'.$inner.'</div>';
    }

    public function test_a_marked_card_token_becomes_a_drawer_link_with_tooltip_attributes(): void
    {
        $project = $this->project();
        $card = $this->card($project, 'Gate "new" work');

        $html = $this->linker->link($this->region('<p>See <span data-ref-card="1">card #1</span>.</p>'), $project);

        self::assertStringContainsString('<a href="/projects/'.$project->id.'/board/cards/'.$card->id.'"', $html);
        self::assertStringContainsString('data-card-drawer-opener', $html);
        self::assertStringContainsString('data-ref-tooltip-title="Gate &quot;new&quot; work"', $html);
        self::assertStringContainsString('data-ref-tooltip-line="', $html);
        self::assertStringContainsString('>card #1</a>', $html);
        self::assertSame(
            strip_tags($this->region('<p>See <span data-ref-card="1">card #1</span>.</p>')),
            strip_tags($html),
        );
    }

    public function test_prose_links_in_any_case_and_in_a_list(): void
    {
        $project = $this->project();
        $this->card($project);
        $this->card($project);
        $this->card($project);

        $html = $this->linker->link($this->region('<p>Card 1 is paused. See cards #2, and 3.</p>'), $project);

        self::assertSame(3, substr_count($html, 'data-card-drawer-opener'));
        self::assertStringContainsString('>Card 1</a> is paused.', $html);
        self::assertStringContainsString('>cards #2</a>, and <a', $html);
        self::assertSame('Card 1 is paused. See cards #2, and 3.', strip_tags($html));
    }

    public function test_an_unknown_card_a_bare_number_and_a_split_phrase_stay_plain(): void
    {
        $project = $this->project();
        $this->card($project);

        $markup = '<p>card 99 is gone. Issue #1 and card <strong>1</strong> and <span data-ref-card="98">card #98</span></p>';
        $html = $this->linker->link($this->region($markup), $project);

        self::assertSame($this->region($markup), $html);
    }

    public function test_code_pre_and_existing_links_stay_literal_and_other_regions_are_untouched(): void
    {
        $project = $this->project();
        $this->card($project);

        $markup = '<code>card 1</code><pre>card 1</pre><a href="/x">card 1</a><label>card 1</label>';
        self::assertSame($this->region($markup), $this->linker->link($this->region($markup), $project));
        self::assertSame('<p>card 1</p>', $this->linker->link('<p>card 1</p>', $project));
    }

    public function test_a_pull_request_token_links_with_the_stored_title_and_state(): void
    {
        $project = $this->project();
        $this->watch($project, 'acme/loupe');
        $this->pullRequest($project, 'acme/loupe', 1295, 'Add the <inbox>', draft: true);

        $html = $this->linker->link($this->region('<span data-ref-pr="acme/loupe#1295">acme/loupe#1295</span>'), $project);

        self::assertStringContainsString('href="https://github.com/acme/loupe/pull/1295"', $html);
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
        self::assertStringContainsString('data-ref-tooltip-title="Add the &lt;inbox&gt;"', $html);
        self::assertStringContainsString('data-ref-tooltip-line="Open · Draft"', $html);
    }

    public function test_a_mixed_case_watched_repository_keeps_the_stored_tooltip(): void
    {
        $project = $this->project();
        $this->watch($project, 'Acme/Loupe');
        $this->pullRequest($project, 'Acme/Loupe', 12, 'Mixed case', PullRequestState::Merged);

        $marked = $this->linker->link($this->region('<span data-ref-pr="acme/LOUPE#12">acme/LOUPE#12</span>'), $project);
        $prose = $this->linker->link($this->region('<p>PR #12</p>'), $project);

        foreach ([$marked, $prose] as $html) {
            self::assertStringContainsString('data-ref-tooltip-title="Mixed case"', $html);
            self::assertStringContainsString('data-ref-tooltip-line="Merged"', $html);
        }
    }

    public function test_a_pull_request_with_no_stored_row_links_without_a_tooltip(): void
    {
        $project = $this->project();
        $this->watch($project, 'acme/loupe');

        $html = $this->linker->link($this->region('<span data-ref-pr="acme/loupe#7">acme/loupe#7</span>'), $project);

        self::assertStringContainsString('href="https://github.com/acme/loupe/pull/7"', $html);
        self::assertStringNotContainsString('data-ref-tooltip', $html);
    }

    public function test_a_nested_path_of_an_unwatched_repository_stays_plain(): void
    {
        $project = $this->project();
        $this->watch($project, 'acme/loupe');

        $markup = '<span data-ref-pr="group/sub/repo#7">group/sub/repo#7</span>';

        self::assertSame($this->region($markup), $this->linker->link($this->region($markup), $project));
    }

    public function test_a_prose_pull_request_links_only_for_exactly_one_watched_repository(): void
    {
        $project = $this->project();
        self::assertSame($this->region('<p>PR #5</p>'), $this->linker->link($this->region('<p>PR #5</p>'), $project));

        $this->watch($project, 'acme/loupe');
        $this->pullRequest($project, 'acme/loupe', 5, 'Fix it', PullRequestState::Merged);
        $one = $this->linker->link($this->region('<p>pull request 5</p>'), $project);
        self::assertStringContainsString('href="https://github.com/acme/loupe/pull/5"', $one);
        self::assertStringContainsString('data-ref-tooltip-line="Merged"', $one);

        $this->watch($project, 'acme/other');
        self::assertSame($this->region('<p>PR 5</p>'), $this->linker->link($this->region('<p>PR 5</p>'), $project));
    }

    public function test_a_region_with_many_references_runs_a_bounded_number_of_queries(): void
    {
        $project = $this->project();
        $this->watch($project, 'acme/loupe');
        $text = '';
        for ($i = 1; $i <= 12; ++$i) {
            $this->card($project);
            $this->pullRequest($project, 'acme/loupe', $i, 'PR '.$i);
            $text .= '<p><span data-ref-card="'.$i.'">card #'.$i.'</span> <span data-ref-pr="acme/loupe#'.$i.'">acme/loupe#'.$i.'</span></p>';
        }
        $this->em->clear();
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $html = $this->linker->link($this->region($text), $project);

        self::assertSame(24, substr_count($html, '<a '));
        $statements = array_merge(...array_map(array_values(...), array_values($queries->getData())));
        self::assertNotEmpty($statements);
        self::assertLessThanOrEqual(4, \count($statements));
    }

    public function test_many_unwatched_pull_request_tokens_ask_the_forge_connection_once(): void
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($project, random_int(1, \PHP_INT_MAX), 'acme', GitHubRepositorySelection::All));
        $this->em->flush();
        $text = '';
        for ($i = 1; $i <= 12; ++$i) {
            $text .= '<p><span data-ref-pr="other/repo'.$i.'#'.$i.'">other/repo'.$i.'#'.$i.'</span></p>';
        }
        $this->em->clear();
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $html = $this->linker->link($this->region($text), $project);

        self::assertSame(12, substr_count($html, 'href="https://github.com/other/repo'));
        $statements = array_merge(...array_map(array_values(...), array_values($queries->getData())));
        self::assertCount(2, $statements);
    }

    public function test_a_watched_github_repository_links_unwatched_tokens_with_no_connection_query(): void
    {
        $project = $this->project();
        $this->watch($project, 'acme/loupe');
        $text = '';
        for ($i = 1; $i <= 12; ++$i) {
            $text .= '<p><span data-ref-pr="other/repo#'.$i.'">other/repo#'.$i.'</span></p>';
        }
        $this->em->clear();
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $html = $this->linker->link($this->region($text), $project);

        self::assertSame(12, substr_count($html, 'href="https://github.com/other/repo/pull/'));
        $statements = array_merge(...array_map(array_values(...), array_values($queries->getData())));
        self::assertCount(1, $statements);
    }
}
