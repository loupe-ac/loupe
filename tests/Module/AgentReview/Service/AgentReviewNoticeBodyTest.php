<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Service\AgentReviewNoticeBody;
use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Board\Service\PullRequestNoticeBody;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\AgentReview\AgentReviewScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AgentReviewNoticeBodyTest extends KernelTestCase
{
    use AgentReviewScenario;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('agent-review-notice');
    }

    public function test_the_container_tags_the_body_for_the_notice_queue(): void
    {
        self::assertInstanceOf(PullRequestNoticeBody::class, self::getContainer()->get(AgentReviewNoticeBody::class));
    }

    public function test_it_supports_only_its_own_keys(): void
    {
        $body = $this->body();

        self::assertTrue($body->supports(AgentReviewNoticeBody::key(Uuid::v7())));
        self::assertFalse($body->supports('stale-approval:abc'));
    }

    public function test_the_comment_holds_the_verdict_the_summary_the_findings_and_the_links(): void
    {
        $review = $this->stored(
            'The change misses one case.',
            [
                $this->finding(AgentReviewSeverity::Nit, 'Naming', 'assets/styles/app.css', 12, 12),
                $this->finding(AgentReviewSeverity::Important, 'Null read', 'src/Foo.php', 3, 5),
                $this->finding(AgentReviewSeverity::Important, 'No code builds the rule', null, null, null, AgentReviewCategory::Spec),
            ],
        );

        $text = $this->of($review);

        self::assertStringContainsString('**Loupe agent review** of `aaaaaaa`: failed, 2 important findings, 1 nit', $text);
        self::assertStringContainsString("\n\nThe change misses one case.\n\n", $text);
        self::assertStringContainsString("- important · `src/Foo.php:3-5` · `Null read`\n- important · Spec · `No code builds the rule`\n- nit · `assets/styles/app.css:12` · `Naming`", $text);
        self::assertStringContainsString('[See the check](https://github.com/acme/widgets/runs/4242)', $text);
        self::assertStringContainsString('[Open the card](', $text);
        self::assertStringContainsString($review->card->id?->toRfc4122() ?? '', $text);
        self::assertStringNotContainsString('<!--', $text);
    }

    public function test_a_passed_review_says_so(): void
    {
        $review = $this->stored('Fine.', [$this->finding(AgentReviewSeverity::Nit, 'Naming', 'a.php', 1, 1)], AgentReviewConclusion::Success);

        self::assertStringContainsString('of `aaaaaaa`: passed, 1 nit', $this->of($review));
    }

    public function test_it_lists_20_findings_and_counts_the_rest(): void
    {
        $findings = [];
        for ($i = 1; $i <= 25; ++$i) {
            $findings[] = $this->finding(AgentReviewSeverity::Important, 'Finding '.$i, 'src/F'.$i.'.php', $i, $i);
        }

        $text = $this->of($this->stored('Many.', $findings));

        self::assertSame(20, substr_count($text, "\n- important · "));
        self::assertStringContainsString('`Finding 20`', $text);
        self::assertStringNotContainsString('`Finding 21`', $text);
        self::assertStringContainsString('- …and 5 more findings on the check.', $text);
    }

    public function test_exactly_20_findings_leave_no_more_line(): void
    {
        $findings = [];
        for ($i = 1; $i <= 20; ++$i) {
            $findings[] = $this->finding(AgentReviewSeverity::Nit, 'Finding '.$i, 'src/F.php', $i, $i);
        }

        $text = $this->of($this->stored('Twenty.', $findings));

        self::assertSame(20, substr_count($text, "\n- nit · "));
        self::assertStringNotContainsString('on the check.', $text);
    }

    public function test_a_mention_in_the_summary_cannot_notify_anyone(): void
    {
        $text = $this->of($this->stored('Ping @octocat and @org/team now.', [$this->finding(AgentReviewSeverity::Nit, 'Naming', 'a.php', 1, 1)]));

        self::assertStringContainsString("@\u{200B}octocat and @\u{200B}org/team", $text);
        self::assertSame(2, substr_count($text, '@'));
        self::assertSame(2, substr_count($text, "@\u{200B}"));
    }

    public function test_a_long_summary_is_cut_to_4000_characters(): void
    {
        $text = $this->of($this->stored(str_repeat('x', 5000), [$this->finding(AgentReviewSeverity::Nit, 'Naming', 'a.php', 1, 1)]));

        self::assertStringContainsString(str_repeat('x', 3999).'…', $text);
        self::assertStringNotContainsString(str_repeat('x', 4000), $text);
    }

    public function test_titles_and_paths_stay_in_code_spans_and_a_long_title_is_cut(): void
    {
        $title = '@octocat `see` [here](https://evil.example) '.str_repeat('y', 300);
        $text = $this->of($this->stored('Summary.', [$this->finding(AgentReviewSeverity::Nit, $title, 'src/`x`.php', 1, 2)]));

        self::assertStringContainsString('- nit · `src/x.php:1-2` · `@octocat see [here](https://evil.example) ', $text);
        self::assertStringNotContainsString(str_repeat('y', 200), $text);
        self::assertSame(0, substr_count(explode("\n", $this->lineWith($text, 'nit · '))[0], '`') % 2);
    }

    public function test_a_review_with_no_check_run_links_the_card_only(): void
    {
        $review = $this->stored('Summary.', [$this->finding(AgentReviewSeverity::Nit, 'Naming', 'a.php', 1, 1)]);
        $review->checkRunId = null;

        $text = $this->of($review);

        self::assertStringNotContainsString('[See the check]', $text);
        self::assertStringContainsString('[Open the card](', $text);
    }

    public function test_the_notice_holds_while_the_review_exists_and_the_pull_request_is_open(): void
    {
        $review = $this->stored('Summary.', [$this->finding(AgentReviewSeverity::Nit, 'Naming', 'a.php', 1, 1)]);
        $notice = $this->notice($review);

        self::assertTrue($this->body()->stillHolds($notice, $review->pullRequest));

        $review->pullRequest->state = PullRequestState::Merged;
        self::assertFalse($this->body()->stillHolds($notice, $review->pullRequest));

        $review->pullRequest->state = PullRequestState::Open;
        $gone = new PullRequestNotice($this->project, $review->pullRequest->id ?? Uuid::v7(), 'github', 'acme/widgets', 7, AgentReviewNoticeBody::key(Uuid::v7()), new \DateTimeImmutable());
        self::assertFalse($this->body()->stillHolds($gone, $review->pullRequest));
    }

    private function body(): AgentReviewNoticeBody
    {
        $body = self::getContainer()->get(AgentReviewNoticeBody::class);
        self::assertInstanceOf(AgentReviewNoticeBody::class, $body);

        return $body;
    }

    private function of(AgentReview $review): string
    {
        return $this->body()->of($this->notice($review));
    }

    private function notice(AgentReview $review): PullRequestNotice
    {
        return new PullRequestNotice(
            $this->project,
            $review->pullRequest->id ?? throw new \LogicException('A flushed pull request has an id.'),
            'github',
            'acme/widgets',
            7,
            AgentReviewNoticeBody::key($review->id ?? throw new \LogicException('A flushed review has an id.')),
            new \DateTimeImmutable(),
        );
    }

    /** @param list<AgentReviewFinding> $findings */
    private function stored(string $summary, array $findings, AgentReviewConclusion $conclusion = AgentReviewConclusion::Failure): AgentReview
    {
        $card = $this->card($this->project);
        $pullRequest = $this->pullRequest($this->project);
        $review = new AgentReview($this->project, $card, $pullRequest, $pullRequest->headSha ?? '', $summary, $conclusion, $findings);
        $review->checkRunId = 4242;
        $this->em->persist($review);
        $this->em->flush();

        return $review;
    }

    private function finding(AgentReviewSeverity $severity, string $title, ?string $path, ?int $start, ?int $end, AgentReviewCategory $category = AgentReviewCategory::Code): AgentReviewFinding
    {
        return new AgentReviewFinding($path, $start, $end, $severity, $title, 'A body that the comment leaves out.', $category);
    }

    private function lineWith(string $text, string $needle): string
    {
        foreach (explode("\n", $text) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        return '';
    }
}
