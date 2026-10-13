<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ShowCardControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_card_page_shows_a_site_review_badge_for_a_widget_card_only(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-source-badge@example.com');
        $project = $this->project($em, $owner);
        $widget = new Card(
            project: $project,
            column: $this->column($project, 'backlog'),
            title: 'From the widget',
            body: '',
            number: 50,
            origin: Actor::Reviewer,
        );
        $em->persist($widget);
        $plain = $this->card($em, $project, 'By an agent');
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$widget->id);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.lp-card-drawer__identity .lp-tag--purple')->count());

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$plain->id);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.lp-card-drawer__identity .lp-tag--purple')->count());
    }

    public function test_the_status_box_names_the_winning_state_and_lists_the_others(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-status-box@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Stuck and working');
        $card->markers = [
            ['key' => 'paused', 'kind' => 'stuck', 'label' => 'marker.paused', 'remedy' => 'marker.paused.remedy', 'params' => ['%reason%' => 'review-failed'], 'since' => new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM)],
            ['key' => 'work_requested', 'kind' => 'working', 'label' => 'marker.work_requested', 'remedy' => 'marker.work_requested.remedy', 'params' => [], 'since' => new \DateTimeImmutable('-30 minutes')->format(\DATE_ATOM)],
            ['key' => 'unmanaged', 'kind' => 'chip', 'label' => 'marker.unmanaged', 'remedy' => null, 'params' => [], 'since' => new \DateTimeImmutable('-2 hours')->format(\DATE_ATOM)],
        ];
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $box = $crawler->filter('[data-card-state="stuck"].lp-status-box--stuck');
        self::assertCount(1, $box);
        self::assertStringContainsString('Paused: review-failed.', $box->text());
        self::assertStringContainsString('Stuck for 1 h', $box->filter('.lp-status-box__chip')->text());
        self::assertStringContainsString('1 hour ago', $box->filter('.lp-status-box__fields')->text());
        self::assertStringContainsString('Fix the cause of the pause', $box->filter('.lp-status-box__field--wide')->text());
        $others = $box->filter('.lp-status-box__other--working');
        self::assertCount(1, $others);
        self::assertStringContainsString('Work waits for a bridge to take it.', $others->text());
        self::assertStringContainsString('Unmanaged: the workflow does not act on this card.', $box->filter('.lp-status-box__other--chip')->text());
    }

    public function test_a_card_with_only_chips_shows_a_neutral_status_box_with_the_chip_text(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-status-box-chip@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Only a note');
        $card->markers = [
            ['key' => 'unmanaged', 'kind' => 'chip', 'label' => 'marker.unmanaged', 'remedy' => 'marker.unmanaged.remedy', 'params' => [], 'since' => new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM)],
        ];
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $box = $crawler->filter('[data-card-state="chip"].lp-status-box--chip');
        self::assertCount(1, $box);
        self::assertStringContainsString('Unmanaged: the workflow does not act on this card.', $box->filter('.lp-status-box__lead')->text());
        self::assertStringContainsString('Note for 1 h', $box->filter('.lp-status-box__chip')->text());
        self::assertCount(0, $box->filter('.lp-status-box__other'));
    }

    public function test_the_status_box_is_absent_for_a_card_in_no_state(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-status-box-none@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Nothing to say');
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#card-panel-overview'));
        self::assertCount(0, $crawler->filter('.lp-status-box'));
    }

    public function test_the_card_page_shows_the_stored_state_of_each_pull_request(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-pull-state@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Ship the state');
        $failing = $this->link($card, 7);
        $merged = $this->link($card, 8);
        $draft = $this->link($card, 9);
        $unread = $this->link($card, 10);
        $card->replacePullRequests($failing, $merged, $draft, $unread);

        $row = $this->row($em, $project, 7);
        $row->checks = PullRequestChecks::Failed;
        $row->failedChecks = ['phpunit', 'phpstan'];
        $row->mergeability = PullRequestMergeability::Conflicting;
        $row->review = PullRequestReview::ChangesRequested;
        $this->row($em, $project, 8)->state = PullRequestState::Merged;
        $draftRow = $this->row($em, $project, 9);
        $draftRow->draft = true;
        $draftRow->checks = PullRequestChecks::Passed;
        $draftRow->review = PullRequestReview::Approved;
        // Linking creates the row before any forge read, so it holds only defaults.
        $em->persist(new ForgePullRequest($project, 'github', 'loupe/loupe', 10));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $failingRow = $crawler->filter('[data-linked-pull-request="'.$failing->id.'"]');
        self::assertSame(['Open', 'Checks failed', 'Conflict', 'Changes requested'], $this->chips($failingRow));
        self::assertSame(3, $failingRow->filter('.lp-status-chip--failed')->count());
        self::assertSame('Failed checks: phpunit, phpstan', trim($failingRow->filter('.lp-pull-request-state__failed')->text()));
        self::assertSame(['Merged'], $this->chips($crawler->filter('[data-linked-pull-request="'.$merged->id.'"]')));
        self::assertSame(['Draft', 'Checks passed', 'Approved'], $this->chips($crawler->filter('[data-linked-pull-request="'.$draft->id.'"]')));
        $unreadRow = $crawler->filter('[data-linked-pull-request="'.$unread->id.'"]');
        self::assertSame([], $this->chips($unreadRow));
        self::assertStringContainsString('Not reported', $unreadRow->text());
    }

    public function test_the_card_page_says_an_approval_of_an_older_head_is_outdated(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-pull-outdated@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Approved before a push');
        $outdated = $this->link($card, 7);
        $current = $this->link($card, 8);
        $card->replacePullRequests($outdated, $current);
        $stale = $this->approved($this->onMain($this->row($em, $project, 7)));
        $stale->headSha = 'pushed7';
        $stale->checks = PullRequestChecks::Passed;
        $this->approved($this->onMain($this->row($em, $project, 8)))->checks = PullRequestChecks::Passed;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $outdatedRow = $crawler->filter('[data-linked-pull-request="'.$outdated->id.'"]');
        self::assertSame(['Open', 'Checks passed', 'Approval outdated'], $this->chips($outdatedRow));
        self::assertSame(1, $outdatedRow->filter('.lp-status-chip--pending')->count());
        self::assertSame(['Open', 'Checks passed', 'Approved'], $this->chips($crawler->filter('[data-linked-pull-request="'.$current->id.'"]')));
    }

    public function test_the_card_page_says_not_reported_when_no_state_is_stored(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-pull-no-state@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'No state yet');
        $link = $this->link($card, 7);
        $card->replacePullRequests($link);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-linked-pull-request="'.$link->id.'"]');
        self::assertCount(1, $row);
        self::assertSame([], $this->chips($row));
        self::assertStringContainsString('Not reported', $row->text());
    }

    public function test_the_card_page_names_the_approver_of_an_overridden_agent_review_and_links_the_check(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-review-override@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Ship it');
        $link = $this->link($card, 7);
        $card->replacePullRequests($link);
        $row = $this->approved($this->onMain($this->row($em, $project, 7)));
        $row->latestApprovedAt = new \DateTimeImmutable('+1 hour');
        $row->latestApprovalId = 'PRR_1';
        $row->latestApproverLogin = 'ubermuda';
        $review = new AgentReview($project, $card, $row, 'head7', 'Failed.', AgentReviewConclusion::Failure, []);
        $review->checkRunId = 4242;
        $em->persist($review);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $note = $crawler->filter('[data-linked-pull-request="'.$link->id.'"] [data-agent-review-override]');
        self::assertCount(1, $note);
        self::assertStringContainsString('Agent review failed, overridden by the approval of ubermuda.', $note->text());
        self::assertSame('https://github.com/loupe/loupe/runs/4242', $note->filter('a')->attr('href'));
    }

    private function link(Card $card, int $number): CardPullRequest
    {
        return new CardPullRequest(
            card: $card,
            url: 'https://github.com/loupe/loupe/pull/'.$number,
            forge: Forge::GitHub,
            repository: 'loupe/loupe',
            number: $number,
        );
    }

    private function row(EntityManagerInterface $em, Project $project, int $number): ForgePullRequest
    {
        $row = new ForgePullRequest($project, 'github', 'loupe/loupe', $number);
        $row->refreshedAt = new \DateTimeImmutable();
        $em->persist($row);

        return $row;
    }

    private function onMain(ForgePullRequest $row): ForgePullRequest
    {
        $row->headSha = 'head'.$row->number;
        $row->baseBranch = 'main';
        $row->defaultBranch = 'main';

        return $row;
    }

    private function approved(ForgePullRequest $row): ForgePullRequest
    {
        $row->approvalId = 'review-'.$row->number;
        $row->approvalSha = $row->headSha;
        $row->coveredSha = $row->headSha;
        $row->approvedAt = new \DateTimeImmutable('-'.$row->number.' minutes');
        $row->review = PullRequestReview::Approved;

        return $row;
    }

    /** @return list<string> */
    private function chips(Crawler $row): array
    {
        return $row->filter('.lp-pull-request-state > .lp-status-chip')->each(
            static function (Crawler $chip): string {
                $tooltip = $chip->filter('.lp-tooltip');

                return trim(str_replace(0 === $tooltip->count() ? '' : $tooltip->text(), '', $chip->text()));
            },
        );
    }
}
