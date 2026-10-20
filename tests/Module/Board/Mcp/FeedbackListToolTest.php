<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\FeedbackListTool;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\ReviewSurface;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeedbackListToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private FeedbackListTool $tool;
    private CardCreateTool $createTool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(FeedbackListTool::class);
        self::assertInstanceOf(FeedbackListTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;
    }

    public function test_the_default_lists_the_pending_feedback_with_its_card(): void
    {
        $project = $this->makeProject('feedback-list');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->card(($this->createTool)('Fix the header', 'Body', 'feature')['cardId']);

        $first = $this->feedback($project, 0, 'The logo is blurry', $card, context: 'card:preview');
        $first->addAnchor('header .logo', 'Logo', 'Acme', 'the ', ' mark');
        $first->strokes = [['space' => 'page', 'points' => [[0.1, 0.2], [0.3, 0.4]]]];
        $this->feedback($project, 1, 'Already fixed', $card, SiteReviewCommentStatus::Addressed);
        $unlinked = $this->feedback($project, 2, 'No card for this one', null);
        $this->em->flush();
        $this->em->clear();

        $result = $this->call();

        self::assertSame([
            [
                'id' => (string) $first->id,
                'url' => 'https://app.example/page',
                'surface' => 'web',
                'native' => null,
                'screenshot' => null,
                'anchors' => [[
                    'selector' => 'header .logo',
                    'text' => 'Logo',
                    'quote' => 'Acme',
                    'quotePrefix' => 'the ',
                    'quoteSuffix' => ' mark',
                ]],
                'body' => 'The logo is blurry',
                'hasDrawing' => true,
                'status' => 'pending',
                'context' => 'card:preview',
                'createdAt' => $first->createdAt->format(\DATE_ATOM),
                'cardId' => (string) $card->id,
                'number' => $card->number,
                'title' => 'Fix the header',
            ],
            [
                'id' => (string) $unlinked->id,
                'url' => 'https://app.example/page',
                'surface' => 'web',
                'native' => null,
                'screenshot' => null,
                'anchors' => [],
                'body' => 'No card for this one',
                'hasDrawing' => false,
                'status' => 'pending',
                'context' => null,
                'createdAt' => $unlinked->createdAt->format(\DATE_ATOM),
                'cardId' => null,
                'number' => null,
                'title' => null,
            ],
        ], $result['feedback']);
    }

    public function test_a_status_filter_narrows_the_list(): void
    {
        $project = $this->makeProject('feedback-list-status');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->card(($this->createTool)('Card', 'Body', 'feature')['cardId']);

        $this->feedback($project, 0, 'Pending one', $card);
        $this->feedback($project, 1, 'Addressed one', $card, SiteReviewCommentStatus::Addressed);
        $this->feedback($project, 2, 'Resolved one', $card, SiteReviewCommentStatus::Resolved);
        $this->em->flush();

        self::assertSame(['Addressed one'], array_column($this->call('addressed')['feedback'], 'body'));
        self::assertSame(['Resolved one'], array_column($this->call('resolved')['feedback'], 'body'));
        self::assertSame(['Pending one'], array_column($this->call('pending')['feedback'], 'body'));
        self::assertSame(['Pending one', 'Addressed one', 'Resolved one'], array_column($this->call('all')['feedback'], 'body'));
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('feedback-list-unknown'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown status "open". Use pending, addressed, resolved or all.');
        $this->call('open');
    }

    public function test_another_projects_feedback_is_not_listed(): void
    {
        $theirs = $this->makeProject('feedback-list-theirs');
        $this->actAsMcpTokenBoundTo($theirs);
        $theirCard = $this->card(($this->createTool)('Theirs', 'Body', 'feature')['cardId']);
        $this->feedback($theirs, 0, 'Not yours', $theirCard);

        $mine = $this->makeProject('feedback-list-mine');
        $this->actAsMcpTokenBoundTo($mine);
        $myCard = $this->card(($this->createTool)('Mine', 'Body', 'feature')['cardId']);
        $this->feedback($mine, 0, 'Yours', $myCard);
        $this->em->flush();

        self::assertSame(['Yours'], array_column($this->call()['feedback'], 'body'));
    }

    public function test_the_text_content_carries_the_same_json_as_the_structured_result(): void
    {
        $project = $this->makeProject('feedback-list-text');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->card(($this->createTool)('Card', 'Body', 'feature')['cardId']);
        $this->feedbackNote($project, $card, 0, screenshot: false);
        $this->em->flush();

        $result = ($this->tool)();

        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertSame($result->structuredContent, json_decode((string) $result->content[0]->text, true));
        self::assertNotNull($result->structuredContent);
        self::assertArrayHasKey('nextImageOffset', $result->structuredContent);
        self::assertNull($result->structuredContent['nextImageOffset']);
    }

    #[TestWith([10, null, 0])]
    #[TestWith([11, 10, 1])]
    #[TestWith([12, 10, 2])]
    public function test_images_come_ten_at_a_time_newest_first_and_the_offset_reaches_every_one(int $count, ?int $next, int $rest): void
    {
        $project = $this->makeProject('feedback-list-paging');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->card(($this->createTool)('Card', 'Body', 'feature')['cardId']);
        for ($position = 0; $position < $count; ++$position) {
            $this->feedbackNote($project, $card, $position);
        }
        $this->em->flush();
        $this->em->clear();
        $cardId = (string) $card->id;

        $newestFirst = array_map($this->feedbackPng(...), range($count - 1, 0));
        self::assertSame(\array_slice($newestFirst, 0, 10), $this->images(null, $cardId));
        self::assertSame($next, $this->call(null, $cardId)['nextImageOffset']);
        self::assertCount($count, $this->call(null, $cardId)['feedback']);

        if (null !== $next) {
            self::assertSame(\array_slice($newestFirst, $next), $this->images(null, $cardId, $next));
            self::assertCount($rest, $this->images(null, $cardId, $next));
            self::assertNull($this->call(null, $cardId, $next)['nextImageOffset']);
        }
    }

    public function test_only_pending_notes_with_a_screenshot_carry_an_image_and_every_note_reports_its_size(): void
    {
        $project = $this->makeProject('feedback-list-open');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->card(($this->createTool)('Card', 'Body', 'feature')['cardId']);
        $this->feedbackNote($project, $card, 0);
        $this->feedbackNote($project, $card, 1, screenshot: false);
        $this->feedbackNote($project, $card, 2, status: SiteReviewCommentStatus::Addressed);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([$this->feedbackPng(0)], $this->images('all'));
        self::assertSame([], $this->images('addressed'));
        self::assertSame(
            [['width' => 1, 'height' => 1], null, ['width' => 3, 'height' => 1]],
            array_column($this->call('all')['feedback'], 'screenshot'),
        );
    }

    public function test_a_card_id_limits_the_feedback_and_its_images_to_that_card(): void
    {
        $project = $this->makeProject('feedback-list-card');
        $this->actAsMcpTokenBoundTo($project);
        $mine = $this->card(($this->createTool)('Mine', 'Body', 'feature')['cardId']);
        $other = $this->card(($this->createTool)('Other', 'Body', 'feature')['cardId']);
        $this->feedbackNote($project, $mine, 0);
        $this->feedbackNote($project, $other, 1);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['Note 0'], array_column($this->call(null, (string) $mine->id)['feedback'], 'body'));
        self::assertSame([$this->feedbackPng(0)], $this->images(null, (string) $mine->id));
        self::assertSame([$this->feedbackPng(1), $this->feedbackPng(0)], $this->images());
    }

    public function test_a_native_note_reports_its_surface_and_facts(): void
    {
        $project = $this->makeProject('feedback-list-native');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->card(($this->createTool)('Card', 'Body', 'feature')['cardId']);
        $facts = ['app' => ['bundleId' => 'dev.example.app'], 'screen' => 'Settings'];
        $comment = new SiteReviewComment($project, 0, 'Clipped', null, surface: ReviewSurface::IosSimulator, native: $facts);
        $this->em->persist($comment);
        $this->em->persist(new CardSiteReviewComment($card, $comment));
        $this->em->flush();
        $this->em->clear();

        $item = $this->call()['feedback'][0];

        self::assertSame('ios-simulator', $item['surface']);
        self::assertSame($facts, $item['native']);
        self::assertNull($item['url']);
    }

    public function test_a_card_of_another_project_is_refused(): void
    {
        $theirs = $this->makeProject('feedback-list-card-theirs');
        $this->actAsMcpTokenBoundTo($theirs);
        $theirCard = ($this->createTool)('Theirs', 'Body', 'feature')['cardId'];
        $this->actAsMcpTokenBoundTo($this->makeProject('feedback-list-card-mine'));

        $this->expectException(ToolCallException::class);
        $this->call(null, $theirCard);
    }

    public function test_an_unbound_token_is_refused(): void
    {
        $project = $this->makeProject('feedback-list-unbound');
        $this->feedback($project, 0, 'Not reachable', null);
        $this->em->flush();
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        $this->call();
    }

    /** @return array<string, mixed> */
    private function call(?string $status = null, ?string $cardId = null, int $imageOffset = 0): array
    {
        return ($this->tool)($status, $cardId, $imageOffset)->structuredContent ?? self::fail('feedback_list returned no structured content.');
    }

    /** @return list<string> the base64 of each image the answer carries, in order */
    private function images(?string $status = null, ?string $cardId = null, int $imageOffset = 0): array
    {
        $images = [];
        foreach (($this->tool)($status, $cardId, $imageOffset)->content as $content) {
            if ($content instanceof ImageContent) {
                self::assertSame('image/png', $content->mimeType);
                $images[] = $content->data;
            }
        }

        return $images;
    }

    private function card(string $cardId): Card
    {
        $card = $this->em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $card);

        return $card;
    }

    private function feedback(Project $project, int $position, string $body, ?Card $card, SiteReviewCommentStatus $status = SiteReviewCommentStatus::Pending, ?string $context = null): SiteReviewComment
    {
        $comment = new SiteReviewComment($project, $position, $body, 'https://app.example/page', $context);
        $comment->status = $status;
        $this->em->persist($comment);

        if (null !== $card) {
            $this->em->persist(new CardSiteReviewComment($card, $comment));
        }

        return $comment;
    }
}
