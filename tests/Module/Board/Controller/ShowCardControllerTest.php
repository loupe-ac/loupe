<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\ReviewSurface;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewScreenshot;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Support\TinyImage;
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

    public function test_a_native_note_shows_its_source_facts_and_screenshot_thumbnail(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-native-note@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Fix the screen');
        $withShot = new SiteReviewComment($project, 0, 'Button is clipped', null, surface: ReviewSurface::IosSimulator, native: [
            'app' => ['name' => 'Fieldnotes', 'bundleId' => 'dev.example.fieldnotes'],
            'device' => ['name' => 'iPhone 17', 'udid' => 'ABC', 'osVersion' => '26.1'],
            'screen' => ['title' => 'Settings'],
            'element' => ['role' => 'Button', 'label' => 'Save', 'identifier' => 'save-button', 'frame' => null],
        ]);
        $withoutShot = new SiteReviewComment($project, 1, 'Title is wrong', null, surface: ReviewSurface::IosSimulator, native: ['app' => ['name' => null, 'bundleId' => 'dev.example.fieldnotes'], 'device' => ['name' => 'iPhone 17', 'udid' => 'ABC', 'osVersion' => '26.1'], 'screen' => ['title' => null], 'element' => null]);
        $png = TinyImage::png(3, 2);
        $links = [new CardSiteReviewComment($card, $withShot), new CardSiteReviewComment($card, $withoutShot)];
        foreach ([$withShot, $withoutShot, ...$links] as $entity) {
            $em->persist($entity);
        }
        $em->persist(new SiteReviewScreenshot($withShot, $project, 'image/png', $png, \strlen($png), 3, 2));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $entries = $crawler->filter('[data-site-feedback]');
        self::assertCount(2, $entries);
        $shot = $crawler->filter('[data-site-feedback="'.$withShot->id.'"]');
        self::assertStringContainsString('iOS simulator', $shot->filter('.lp-card-feedback-list__head')->text());
        $thumbnail = $shot->filter('img.lp-feedback-screenshot__thumbnail');
        self::assertCount(1, $thumbnail);
        self::assertSame('/board/feedback/'.$links[0]->id.'/screenshot', $thumbnail->attr('src'));
        self::assertSame('3', $thumbnail->attr('width'));
        self::assertSame('2', $thumbnail->attr('height'));
        self::assertSame('lazy', $thumbnail->attr('loading'));
        self::assertNotSame('', trim((string) $thumbnail->attr('alt')));
        self::assertCount(1, $shot->filter('dialog img.lp-feedback-screenshot__full'));
        $facts = $shot->filter('.lp-feedback-native-facts')->text();
        foreach (['iPhone 17', '26.1', 'Fieldnotes', 'dev.example.fieldnotes', 'Settings', 'Button', 'Save', 'save-button'] as $fact) {
            self::assertStringContainsString($fact, $facts);
        }

        $bare = $crawler->filter('[data-site-feedback="'.$withoutShot->id.'"]');
        self::assertStringContainsString('iOS simulator', $bare->filter('.lp-card-feedback-list__head')->text());
        self::assertCount(0, $bare->filter('img'));
        self::assertCount(0, $bare->filter('a[target="_blank"]'));
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
        self::assertCount(0, $box->filter('.lp-status-box__other--working'));
        self::assertStringNotContainsString('Work waits for a bridge to take it.', $box->text());
        self::assertStringContainsString('Unmanaged: the workflow does not act on this card.', $box->filter('.lp-status-box__other--chip')->text());
    }

    public function test_a_running_worker_with_waiting_checks_shows_one_line_and_no_also_applies(): void
    {
        $crawler = $this->showCardWithMarkers('card-status-box-working@example.com', [
            ['key' => 'work_requested', 'kind' => 'working', 'label' => 'marker.work_requested', 'remedy' => 'marker.work_requested.remedy', 'params' => [], 'since' => new \DateTimeImmutable('-30 minutes')->format(\DATE_ATOM)],
            ['key' => 'waiting_on_checks', 'kind' => 'waiting', 'label' => 'marker.waiting_on_checks', 'remedy' => 'marker.waiting_on_checks.remedy', 'params' => ['%number%' => 4], 'since' => new \DateTimeImmutable('-10 minutes')->format(\DATE_ATOM)],
        ]);

        $box = $crawler->filter('[data-card-state="working"].lp-status-box--working');
        self::assertCount(1, $box);
        self::assertCount(0, $box->filter('.lp-status-box__other'));
        self::assertStringNotContainsString('Also applies', $box->text());
        self::assertStringNotContainsString('Waiting for the checks', $box->text());
    }

    public function test_a_stuck_state_shows_a_needs_you_marker_as_also_applies(): void
    {
        $crawler = $this->showCardWithMarkers('card-status-box-needs-you@example.com', [
            ['key' => 'paused', 'kind' => 'stuck', 'label' => 'marker.paused', 'remedy' => 'marker.paused.remedy', 'params' => ['%reason%' => 'review-failed'], 'since' => new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM)],
            ['key' => 'open_question', 'kind' => 'needs-you', 'label' => 'marker.open_question', 'remedy' => 'marker.open_question.remedy', 'params' => ['%number%' => 3, '%title%' => 'Which option?'], 'since' => new \DateTimeImmutable('-30 minutes')->format(\DATE_ATOM)],
        ]);

        $box = $crawler->filter('[data-card-state="stuck"]');
        self::assertStringContainsString('Also applies', $box->text());
        self::assertStringContainsString('Question #3 waits for your answer: Which option?', $box->filter('.lp-status-box__other--needs-you')->text());
    }

    public function test_a_waiting_state_shows_the_unmanaged_chip_as_also_applies(): void
    {
        $crawler = $this->showCardWithMarkers('card-status-box-waiting-chip@example.com', [
            ['key' => 'waiting_on_checks', 'kind' => 'waiting', 'label' => 'marker.waiting_on_checks', 'remedy' => 'marker.waiting_on_checks.remedy', 'params' => ['%number%' => 4], 'since' => new \DateTimeImmutable('-10 minutes')->format(\DATE_ATOM)],
            ['key' => 'unmanaged', 'kind' => 'chip', 'label' => 'marker.unmanaged', 'remedy' => null, 'params' => [], 'since' => new \DateTimeImmutable('-2 hours')->format(\DATE_ATOM)],
        ]);

        $box = $crawler->filter('[data-card-state="waiting"]');
        self::assertStringContainsString('Unmanaged: the workflow does not act on this card.', $box->filter('.lp-status-box__other--chip')->text());
    }

    /** @param non-empty-string $email
     * @param list<array{key: string, kind: string, label: string, remedy: ?string, params: array<string, string|int>, since: string}> $markers */
    private function showCardWithMarkers(string $email, array $markers): Crawler
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, $email);
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Marked card');
        $card->markers = $markers;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function test_a_marker_with_a_link_draws_it_under_the_remedy_and_one_without_draws_none(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-status-box-link@example.com');
        $project = $this->project($em, $owner);
        $linked = $this->card($em, $project, 'No bridge');
        $linked->markers = [
            ['key' => 'no-bridge-online', 'kind' => 'stuck', 'label' => 'marker.no_bridge_online', 'remedy' => 'marker.no_bridge_online.remedy', 'params' => [], 'since' => new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM), 'link' => 'agents'],
        ];
        $plain = $this->card($em, $project, 'Plain');
        $plain->markers = [
            ['key' => 'paused', 'kind' => 'stuck', 'label' => 'marker.paused', 'remedy' => 'marker.paused.remedy', 'params' => ['%reason%' => 'review-failed'], 'since' => new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM)],
        ];
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$linked->id);
        self::assertResponseIsSuccessful();
        $field = $crawler->filter('[data-card-state="stuck"] .lp-status-box__field--wide');
        self::assertStringContainsString('Start a bridge, or run `loupe login`.', $field->text());
        $link = $field->filter('a');
        self::assertCount(1, $link);
        self::assertSame('/projects/'.$project->id.'/agents', $link->attr('href'));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$plain->id);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-card-state="stuck"] .lp-status-box__fields a'));
    }

    public function test_the_card_view_links_a_ruleset_mismatch_to_the_ruleset_setup_guide(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'card-status-box-ruleset@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Ruleset');
        $card->markers = [
            ['key' => 'ruleset-mismatch', 'kind' => 'needs-you', 'label' => 'marker.ruleset_mismatch', 'remedy' => 'marker.ruleset_mismatch.remedy', 'params' => ['%number%' => 9], 'since' => new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM), 'link' => 'ruleset-setup'],
        ];
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);
        self::assertResponseIsSuccessful();
        $link = $crawler->filter('[data-card-state="needs-you"] .lp-status-box__field--wide a');
        self::assertCount(1, $link);
        self::assertSame('Read how to set up the ruleset', trim($link->text()));
        self::assertSame('https://loupe.ac/docs/using/workflows/#set-up-the-ruleset', $link->attr('href'));
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
