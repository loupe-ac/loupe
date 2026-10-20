<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Command\NewAnchor;
use App\Module\SiteReview\Command\NewScreenshot;
use App\Module\SiteReview\Command\NewStroke;
use App\Module\SiteReview\Entity\ReviewSurface;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Entity\SiteReviewScreenshot;
use App\Module\SiteReview\Event\SiteReviewCommentCreated;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;
use App\Module\SiteReview\Service\ScreenshotDecoder;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\TinyImage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class AddCommentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SiteReviewCommentRepository $siteReviewCommentRepository;
    private AddCommentHandler $handler;
    private RecordingAuditor $audit;
    private EventDispatcher $events;
    /** @var list<SiteReviewCommentCreated> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $comments = self::getContainer()->get(SiteReviewCommentRepository::class);
        self::assertInstanceOf(SiteReviewCommentRepository::class, $comments);
        $this->siteReviewCommentRepository = $comments;
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);
        $this->dispatched = [];
        $this->events = new EventDispatcher();
        $this->events->addListener(
            SiteReviewCommentCreated::class,
            function (SiteReviewCommentCreated $event): void { $this->dispatched[] = $event; },
        );
        $this->handler = new AddCommentHandler($comments, $this->em, $this->audit->auditor, $this->events);
    }

    public function test_first_comment_is_pending_at_position_zero(): void
    {
        $project = $this->project('add-a@example.com');
        $comment = ($this->handler)(new AddCommentCommand($project, 'hello', 'https://app/x', [new NewAnchor('.a', 'A')]));

        self::assertNotNull($comment->id);
        self::assertSame(SiteReviewCommentStatus::Pending, $comment->status);
        self::assertSame(0, $comment->position);
    }

    public function test_repeated_delivery_emits_one_event_and_one_audit_record(): void
    {
        $project = $this->project('delivery-event@example.com');
        $command = new AddCommentCommand($project, 'hello', 'https://app/x', deliveryId: (string) Uuid::v4());
        $first = ($this->handler)($command);
        $first->status = SiteReviewCommentStatus::Addressed;
        $this->em->flush();
        $repeated = ($this->handler)($command);

        self::assertSame($first->id, $repeated->id);
        self::assertSame(SiteReviewCommentStatus::Addressed, $repeated->status);
        self::assertCount(1, $this->dispatched);
        self::assertSame(['site_review.comment_added'], $this->audit->domainLogLines());
        self::assertSame(1, $this->siteReviewCommentRepository->count(['project' => $project]));
    }

    public function test_delivery_identity_is_scoped_to_its_project(): void
    {
        $firstProject = $this->project('delivery-a@example.com');
        $secondProject = $this->project('delivery-b@example.com');
        $deliveryId = (string) Uuid::v4();
        $first = ($this->handler)(new AddCommentCommand($firstProject, 'hello', 'https://app/x', deliveryId: $deliveryId));
        $second = ($this->handler)(new AddCommentCommand($secondProject, 'hello', 'https://app/x', deliveryId: $deliveryId));

        self::assertNotNull($first->id);
        self::assertFalse($first->id->equals($second->id));
        self::assertSame($firstProject, $first->project);
        self::assertSame($secondProject, $second->project);
        self::assertCount(2, $this->dispatched);
    }

    public function test_legacy_deliveries_without_identity_remain_independent(): void
    {
        $project = $this->project('delivery-legacy@example.com');
        $command = new AddCommentCommand($project, 'hello', 'https://app/x');
        $first = ($this->handler)($command);
        $second = ($this->handler)($command);

        self::assertNotNull($first->id);
        self::assertFalse($first->id->equals($second->id));
        self::assertNull($first->deliveryId);
        self::assertNull($second->deliveryId);
        self::assertCount(2, $this->dispatched);
    }

    #[TestWith(['body'])]
    #[TestWith(['url'])]
    #[TestWith(['anchors'])]
    #[TestWith(['strokes'])]
    #[TestWith(['context'])]
    #[TestWith(['deliveryScope'])]
    public function test_reusing_delivery_identity_with_changed_content_is_rejected(string $field): void
    {
        $project = $this->project('delivery-conflict@example.com');
        $deliveryId = (string) Uuid::v4();
        $original = new AddCommentCommand($project, 'hello', 'https://app/x', deliveryId: $deliveryId);
        $first = ($this->handler)($original);
        $changed = new AddCommentCommand(
            $project,
            'body' === $field ? 'changed' : 'hello',
            'url' === $field ? 'https://app/y' : 'https://app/x',
            anchors: 'anchors' === $field ? [new NewAnchor('.a', 'A')] : [],
            strokes: 'strokes' === $field ? [new NewStroke('page', [[0.25, 0.5], [0.75, 0.5]])] : [],
            context: 'context' === $field ? 'card:changed' : null,
            deliveryId: $deliveryId,
            deliveryScope: 'deliveryScope' === $field ? 'elsewhere' : null,
        );
        try {
            ($this->handler)($changed);
            self::fail('A changed delivery must not reuse the original identity.');
        } catch (DomainErrors $error) {
            self::assertSame(['deliveryId' => 'delivery_conflict'], $error->errors);
        }

        self::assertTrue($this->em->isOpen());
        self::assertSame($first->id, ($this->handler)($original)->id);
        self::assertCount(1, $this->dispatched);
        self::assertSame(['site_review.comment_added'], $this->audit->domainLogLines());
        self::assertSame(1, $this->siteReviewCommentRepository->count(['project' => $project]));
    }

    public function test_the_context_is_stored_as_given_and_defaults_to_null(): void
    {
        $project = $this->project('add-context@example.com');
        $marked = ($this->handler)(new AddCommentCommand(
            $project,
            'on the preview',
            'https://preview/x',
            context: 'card:0199c0de-0000-7000-8000-000000000001',
        ));
        $plain = ($this->handler)(new AddCommentCommand($project, 'anywhere else', 'https://app/x'));

        $this->em->clear();
        $reloaded = $this->em->find(SiteReviewComment::class, $marked->id);
        self::assertNotNull($reloaded);
        self::assertSame('card:0199c0de-0000-7000-8000-000000000001', $reloaded->context);

        $reloadedPlain = $this->em->find(SiteReviewComment::class, $plain->id);
        self::assertNotNull($reloadedPlain);
        self::assertNull($reloadedPlain->context);
    }

    public function test_a_saved_comment_is_announced_with_an_id_a_listener_can_use(): void
    {
        $project = $this->project('add-event@example.com');
        $comment = ($this->handler)(new AddCommentCommand($project, 'hello', 'https://app/x', context: 'card:abc'));

        self::assertCount(1, $this->dispatched);
        self::assertSame($comment, $this->dispatched[0]->comment);
        // A listener resolving the context has to be able to name the row it
        // links to, so the id must already exist when the event goes out.
        self::assertNotNull($this->dispatched[0]->comment->id);
        self::assertSame('card:abc', $this->dispatched[0]->comment->context);
    }

    public function test_strokes_are_persisted_and_an_empty_drawing_stores_null(): void
    {
        $project = $this->project('add-strokes@example.com');
        $drawn = ($this->handler)(new AddCommentCommand(
            $project,
            'point here',
            'https://app/x',
            [new NewAnchor('.a', 'A')],
            [new NewStroke('anchor', [[0.25, 0.5], [0.75, 0.5]])],
        ));
        $plain = ($this->handler)(new AddCommentCommand($project, 'no drawing', 'https://app/x'));

        $this->em->clear();
        $reloaded = $this->em->find(SiteReviewComment::class, $drawn->id);
        self::assertNotNull($reloaded);
        self::assertSame(
            [['space' => 'anchor', 'points' => [[0.25, 0.5], [0.75, 0.5]]]],
            $reloaded->strokes,
        );

        $reloadedPlain = $this->em->find(SiteReviewComment::class, $plain->id);
        self::assertNotNull($reloadedPlain);
        self::assertNull($reloadedPlain->strokes);
    }

    public function test_anchors_are_persisted_in_the_order_they_were_given(): void
    {
        $project = $this->project('add-anchors@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'these two', 'https://app/x', [
            new NewAnchor('.first', 'First'),
            new NewAnchor('.second', 'Second', quote: 'a quoted run'),
        ]));
        $this->em->clear();

        $reloaded = $this->em->find(SiteReviewComment::class, $comment->id);
        self::assertNotNull($reloaded);
        $anchors = array_values($reloaded->anchors->toArray());
        self::assertCount(2, $anchors);
        self::assertSame(['.first', '.second'], array_map(static fn ($a) => $a->selector, $anchors));
        self::assertSame([0, 1], array_map(static fn ($a) => $a->position, $anchors));
        self::assertNull($anchors[0]->quote);
        self::assertSame('a quoted run', $anchors[1]->quote);
    }

    public function test_a_comment_with_no_anchor_is_an_unanchored_page_note(): void
    {
        $project = $this->project('add-note@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'a page note', 'https://app/x'));

        self::assertCount(0, $comment->anchors);
    }

    public function test_deleting_a_comment_removes_its_anchors(): void
    {
        $project = $this->project('add-cascade@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'doomed', 'https://app/x', [
            new NewAnchor('.a', 'A'),
            new NewAnchor('.b', 'B'),
        ]));
        $commentId = (string) $comment->id;

        $this->em->remove($comment);
        $this->em->flush();

        $left = $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM site_review_comment_anchors WHERE comment_id = :id',
            ['id' => $commentId],
        );
        self::assertSame(0, (int) $left);
    }

    public function test_second_comment_increments_position(): void
    {
        $project = $this->project('add-b@example.com');
        ($this->handler)(new AddCommentCommand($project, 'one', 'https://app/x'));
        $second = ($this->handler)(new AddCommentCommand($project, 'two', 'https://app/y'));

        self::assertSame(1, $second->position);
    }

    public function test_position_keeps_incrementing_after_a_send(): void
    {
        $project = $this->project('add-c@example.com');
        ($this->handler)(new AddCommentCommand($project, 'one', 'https://app/x'));
        $this->em->getConnection()->executeStatement(
            'UPDATE site_review_comments SET status = :status WHERE project_id = :project',
            ['status' => 'pending', 'project' => (string) $project->id],
        );

        // No batch boundary anymore: position is a project-wide monotonic
        // counter, so a comment added after a send simply continues it.
        $next = ($this->handler)(new AddCommentCommand($project, 'two', 'https://app/y'));

        self::assertSame(1, $next->position);
    }

    public function test_an_added_comment_is_recorded_on_the_domain_channel(): void
    {
        $project = $this->project('add-audit@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'hello', 'https://app/x', [new NewAnchor('.a', 'A')]));

        $record = $this->audit->record('site_review.comment_added');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('site_review_comment', $record->subject->type);
        self::assertSame((string) $comment->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'commentId' => (string) $comment->id,
        ], $record->context);

        self::assertSame(['site_review.comment_added'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(AddCommentHandler::class);
    }

    /** @return array<string, mixed> */
    private static function nativeFacts(string $screenTitle = 'Settings'): array
    {
        return [
            'app' => ['name' => 'Treadmatic', 'bundleId' => 'com.example.treadmatic'],
            'device' => ['name' => 'iPhone 17 Pro', 'udid' => 'A1B2C3D4', 'osVersion' => 'iOS-26-5'],
            'screen' => ['title' => $screenTitle],
            'element' => ['role' => 'AXButton', 'label' => 'Save', 'identifier' => null, 'frame' => ['x' => 10.0, 'y' => 20.0, 'width' => 80.0, 'height' => 44.0]],
        ];
    }

    private function screenshot(string $bytes): \App\Module\SiteReview\Service\DecodedScreenshot
    {
        return new ScreenshotDecoder()->decode(new NewScreenshot('image/png', base64_encode($bytes)));
    }

    public function test_a_native_note_keeps_its_facts_and_has_no_page(): void
    {
        $project = $this->project('native-note@example.com');

        $comment = ($this->handler)(new AddCommentCommand(
            $project,
            'Button is clipped',
            null,
            surface: ReviewSurface::IosSimulator,
            native: self::nativeFacts(),
        ));
        $this->em->clear();

        $stored = $this->siteReviewCommentRepository->find($comment->id);
        self::assertInstanceOf(SiteReviewComment::class, $stored);
        self::assertSame(ReviewSurface::IosSimulator, $stored->surface);
        self::assertNull($stored->url);
        self::assertSame(self::nativeFacts(), $stored->native);
    }

    public function test_a_screenshot_is_stored_with_its_comment_and_goes_with_it(): void
    {
        $project = $this->project('screenshot-note@example.com');
        $png = TinyImage::png(4, 3);

        $comment = ($this->handler)(new AddCommentCommand($project, 'Look', 'https://app/x', screenshot: $this->screenshot($png)));
        $commentId = (string) $comment->id;
        $this->em->clear();

        $row = $this->em->getConnection()->fetchAssociative('SELECT content_type, size, width, height, project_id FROM site_review_screenshots WHERE comment_id = ?', [$commentId]);
        self::assertSame(['content_type' => 'image/png', 'size' => \strlen($png), 'width' => 4, 'height' => 3, 'project_id' => (string) $project->id], $row);
        $screenshots = self::getContainer()->get(SiteReviewScreenshotRepository::class);
        self::assertInstanceOf(SiteReviewScreenshotRepository::class, $screenshots);
        $screenshot = $screenshots->findOneBy(['comment' => Uuid::fromString($commentId)]);
        self::assertInstanceOf(SiteReviewScreenshot::class, $screenshot);
        self::assertSame($png, $screenshot->contents());

        $stored = $this->siteReviewCommentRepository->find($commentId);
        self::assertInstanceOf(SiteReviewComment::class, $stored);
        $this->em->remove($stored);
        $this->em->flush();

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM site_review_screenshots WHERE comment_id = ?', [$commentId]));
    }

    public function test_a_web_note_without_a_screenshot_keeps_the_hash_it_was_stored_with(): void
    {
        $project = $this->project('hash-stable@example.com');

        $comment = ($this->handler)(new AddCommentCommand(
            $project,
            'hello',
            'https://app/x',
            [new NewAnchor('.a', 'A')],
            deliveryId: (string) Uuid::v4(),
        ));
        $withScope = ($this->handler)(new AddCommentCommand($project, 'hello', 'https://app/x', deliveryId: (string) Uuid::v4(), deliveryScope: 'card:1'));

        $flags = \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION;
        self::assertSame(hash('sha256', json_encode(['hello', 'https://app/x', [new NewAnchor('.a', 'A')], [], null], $flags)), $comment->deliveryHash);
        self::assertSame(hash('sha256', json_encode(['hello', 'https://app/x', [], [], null, 'card:1'], $flags)), $withScope->deliveryHash);
    }

    public function test_a_retry_of_a_native_note_returns_the_stored_note(): void
    {
        $project = $this->project('native-retry@example.com');
        $command = new AddCommentCommand(
            $project,
            'Button is clipped',
            null,
            deliveryId: (string) Uuid::v4(),
            surface: ReviewSurface::IosSimulator,
            native: self::nativeFacts(),
            screenshot: $this->screenshot(TinyImage::png(2, 2)),
        );

        $first = ($this->handler)($command);
        $second = ($this->handler)($command);

        self::assertSame($first, $second);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM site_review_screenshots WHERE comment_id = ?', [(string) $first->id]));
    }

    /** @return iterable<string, array{string}> */
    public static function changedNativeParts(): iterable
    {
        yield 'the image' => ['image'];
        yield 'a native fact' => ['fact'];
        yield 'the surface' => ['surface'];
        yield 'the image added to a note without one' => ['added'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedNativeParts')]
    public function test_a_retry_that_changes_the_image_or_a_native_fact_is_a_conflict(string $change): void
    {
        $project = $this->project('native-conflict-'.str_replace(' ', '-', $change).'@example.com');
        $deliveryId = (string) Uuid::v4();
        $original = new AddCommentCommand(
            $project,
            'Button is clipped',
            'added' === $change ? 'https://app/x' : null,
            deliveryId: $deliveryId,
            surface: 'added' === $change ? ReviewSurface::Web : ReviewSurface::IosSimulator,
            native: 'added' === $change ? null : self::nativeFacts(),
            screenshot: 'added' === $change ? null : $this->screenshot(TinyImage::png(2, 2)),
        );
        ($this->handler)($original);

        $changed = match ($change) {
            'image' => new AddCommentCommand($project, 'Button is clipped', null, deliveryId: $deliveryId, surface: ReviewSurface::IosSimulator, native: self::nativeFacts(), screenshot: $this->screenshot(TinyImage::png(3, 3))),
            'fact' => new AddCommentCommand($project, 'Button is clipped', null, deliveryId: $deliveryId, surface: ReviewSurface::IosSimulator, native: self::nativeFacts('Profile'), screenshot: $this->screenshot(TinyImage::png(2, 2))),
            'surface' => new AddCommentCommand($project, 'Button is clipped', 'https://app/x', deliveryId: $deliveryId, screenshot: $this->screenshot(TinyImage::png(2, 2))),
            default => new AddCommentCommand($project, 'Button is clipped', 'https://app/x', deliveryId: $deliveryId, screenshot: $this->screenshot(TinyImage::png(2, 2))),
        };

        try {
            ($this->handler)($changed);
            self::fail('A changed delivery must not reuse the original identity.');
        } catch (DomainErrors $error) {
            self::assertSame(['deliveryId' => 'delivery_conflict'], $error->errors);
        }
    }

    public function test_the_database_refuses_a_note_whose_surface_and_page_disagree(): void
    {
        $project = $this->project('shape-check@example.com');
        $insert = static fn (string $surface, ?string $url, ?string $native): string => \sprintf(
            "INSERT INTO site_review_comments (id, status, position, body, url, created_at, project_id, surface, native) VALUES (gen_random_uuid(), 'pending', 0, 'b', %s, now(), '%s', '%s', %s)",
            null === $url ? 'NULL' : "'".$url."'",
            (string) $project->id,
            $surface,
            null === $native ? 'NULL' : "'".$native."'",
        );
        $connection = $this->em->getConnection();

        foreach ([['web', null, null], ['web', 'https://a', '{}'], ['ios-simulator', 'https://a', '{}'], ['ios-simulator', null, null]] as [$surface, $url, $native]) {
            // A refused statement aborts the test transaction, so each try gets a savepoint.
            $connection->executeStatement('SAVEPOINT shape_check');
            try {
                $connection->executeStatement($insert($surface, $url, $native));
                self::fail(\sprintf('A %s note with url %s and native %s must be refused.', $surface, var_export($url, true), var_export($native, true)));
            } catch (\Doctrine\DBAL\Exception\DriverException $refused) {
                self::assertStringContainsString('site_review_comments_surface_shape', $refused->getMessage());
            }
            $connection->executeStatement('ROLLBACK TO SAVEPOINT shape_check');
        }

        self::assertSame(1, $connection->executeStatement($insert('web', 'https://a', null)));
        self::assertSame(1, $connection->executeStatement($insert('ios-simulator', null, '{}')));
    }

    /** @param non-empty-string $email */
    private function project(string $email, string $name = 'handler-site'): Project
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
