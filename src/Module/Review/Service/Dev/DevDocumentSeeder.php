<?php

declare(strict_types=1);

namespace App\Module\Review\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\DocumentTagApplier;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the three documents of the showcase: a plan with two versions, an approved spec and a tech design.
 * The plan carries one comment, and the spec has none, so it is the empty twin of the Comments panel.
 * It also writes three approved documents in the project of the keys account: one with a verdict that an API
 * key recorded, one with a comment that an API key wrote, and an empty twin where a person did both.
 * Two product designs in the Lifecycle project carry an approval of the Workflow account.
 */
#[When('dev')]
#[When('test')]
final readonly class DevDocumentSeeder implements DevSeederInterface
{
    public const string KEY = 'documents';

    public const int LIST_FILLERS = 20;

    /** The product designs of the session start cards, by document slug. */
    public const array SESSION_START_SLUGS = [
        'session-start-ask' => 'Product design: a card that waits for a session start answer',
        'session-start-approved' => 'Product design: a card with the session start approved',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private DocumentRepository $documents,
        private DocumentTagApplier $tagApplier,
        private UserRepository $users,
        private ReviewRepository $reviews,
    ) {
    }

    public static function documentId(string $slug): Uuid
    {
        return DevSeedId::of('review/document/'.$slug);
    }

    public static function apiKeyId(): Uuid
    {
        return DevSeedId::of('review/api-key/ci');
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::devProjectId())
            ?? throw new \LogicException('The dev project does not exist.');
        $owner = $context->devUser();

        $rules = $this->documents->find(self::documentId('column-rules'));
        if (!$rules instanceof Document) {
            $rules = new Document($owner, $project, 'Column rules', $context->at(2_700));
            $rules->addVersion(
                "# Column rules\n\n## Renaming a column\n\nA rule points at a column by its stable id, so a rename keeps the rule attached. The board shows the new label everywhere the old one appeared.\n\n## Constraints\n\n1. **C1: A rename keeps every rule attached.** The rule stores the column id, not its label.\n2. **C2: A deleted column disables its rules.** The board lists them for review.\n\nC2 matters less than C1 while columns are rarely deleted.\n",
                '<h1>Column rules</h1><h2>Renaming a column</h2><p>A rule points at a column by its stable id, so a rename keeps the rule attached. The board shows the new label everywhere the old one appeared.</p><h2>Constraints</h2><ol><li><strong>C1: A rename keeps every rule attached.</strong> The rule stores the column id, not its label.</li><li><strong>C2: A deleted column disables its rules.</strong> The board lists them for review.</li></ol><p>C2 matters less than C1 while columns are rarely deleted.</p>',
            );
            $rules->status = DocumentStatus::Approved;
            $this->store($context, $rules, 'column-rules');
        }

        if (!$this->documents->find(self::documentId('worker-run-history')) instanceof Document) {
            $history = new Document($owner, $project, 'Worker run history', $context->at(3_600));
            $history->addVersion(
                "# Worker run history\n\n## The problem\n\nWhen an agent finishes, its output disappears into a terminal session. A person needs to see what ran, what happened, and which card it belonged to.\n\n## Proposed approach\n\nGive every reported run a place in the project. Show its outcome, duration, matched rule, and the card that started it.\n",
                '<h1>Worker run history</h1><h2>The problem</h2><p>When an agent finishes, its output disappears into a terminal session. A person needs to see what ran, what happened, and which card it belonged to.</p><h2>Proposed approach</h2><p>Give every reported run a place in the project. Show its outcome, duration, matched rule, and the card that started it.</p>',
            );
            $history->addVersion(
                "# Worker run history\n\n## The problem\n\nWhen an agent finishes, its output disappears into a terminal session.\n\n## Failure and recovery\n\nA failed run shows the original output and the reason it stopped. A retry starts a new run and preserves the earlier attempt. The matched rule keeps its label after a rename, as C1 requires.\n",
                '<h1>Worker run history</h1><h2>The problem</h2><p>When an agent finishes, its output disappears into a terminal session.</p><h2>Failure and recovery</h2><p>A failed run shows the original output and the reason it stopped. A retry starts a new run and preserves the earlier attempt. The matched rule keeps its label after a rename, as C1 requires.</p>',
                'Answered the failure question.',
            );
            $history->addReference($rules);
            $this->store($context, $history, 'worker-run-history');
        }

        $this->seedHistoryComment($context, $owner);
        $this->seedHistoryReview($context);

        if (!$this->documents->find(self::documentId('first-handoff')) instanceof Document) {
            $onboarding = new Document($owner, $project, 'Tech design: First handoff guide', $context->at(1_800));
            $onboarding->addVersion(
                "# Tech design: First handoff guide\n\n## The first five minutes\n\nMove a card to Ready. The rule of that column starts an agent, and the card shows the run.\n",
                '<h1>Tech design: First handoff guide</h1><h2>The first five minutes</h2><p>Move a card to Ready. The rule of that column starts an agent, and the card shows the run.</p>',
            );
            $this->tagApplier->apply($onboarding, ['tech-design', 'decisions']);
            $this->store($context, $onboarding, 'first-handoff');
        }

        $this->seedApiKeyDocuments($context);
        $this->seedSessionStartDocuments($context);
        $this->seedLiveStates($context, $project, $owner);
        $this->seedListFillers($context);
    }

    /**
     * The archived document and the document with an addressed thread and a reply that the live preview links show.
     */
    private function seedLiveStates(SeedContext $context, Project $project, User $owner): void
    {
        if (!$this->documents->find(self::documentId('live-archived')) instanceof Document) {
            $archived = new Document($owner, $project, 'An archived document', $context->at(900));
            $archived->addVersion(
                "# An archived document\n\nAn owner archived this document. The review page keeps its text and shows the archive band.\n",
                '<h1>An archived document</h1><p>An owner archived this document. The review page keeps its text and shows the archive band.</p>',
            );
            $archived->archivedAt = $context->at(100);
            $this->store($context, $archived, 'live-archived');
        }

        if (!$this->documents->find(self::documentId('live-thread')) instanceof Document) {
            $thread = new Document($owner, $project, 'A document with an answered thread', $context->at(950));
            $version = $thread->addVersion(
                "# A document with an answered thread\n\nOne thread of this document is addressed and holds a reply.\n",
                '<h1>A document with an answered thread</h1><p>One thread of this document is addressed and holds a reply.</p>',
            );
            $this->store($context, $thread, 'live-thread');

            $root = $this->comment($context, $version, $owner, false);
            $root->status = CommentStatus::Addressed;
            $context->ids->assign($root, DevSeedId::of('review/comment/live-thread'));
            $this->em->persist($root);
            $reply = new Comment($version, $this->users->workflow(), 'Reworded the section as asked.', Anchor::unanchored(), $root, createdAt: $context->at(200));
            $context->ids->assign($reply, DevSeedId::of('review/comment/live-thread-reply'));
            $this->em->persist($reply);
            $this->em->flush();
        }
    }

    /**
     * Twenty documents in the Lifecycle project, so its list holds a second page of two rows.
     */
    private function seedListFillers(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::lifecycleProjectId())
            ?? throw new \LogicException('The lifecycle project does not exist.');

        for ($number = 1; $number <= self::LIST_FILLERS; ++$number) {
            $slug = \sprintf('list-filler-%02d', $number);
            if ($this->documents->find(self::documentId($slug)) instanceof Document) {
                continue;
            }

            $title = \sprintf('Filler document %02d', $number);
            $document = new Document($context->devUser(), $project, $title, $context->at(4_000 + $number * 10));
            $document->addVersion(
                \sprintf("# %s\n\nA short document that fills the list.\n", $title),
                \sprintf('<h1>%s</h1><p>A short document that fills the list.</p>', $title),
            );
            $this->store($context, $document, $slug);
        }
    }

    /**
     * Two approved product designs in the Lifecycle project, each approved by the Workflow account.
     * A card with no tech design and one of them is the case that starts a session start question.
     */
    private function seedSessionStartDocuments(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::lifecycleProjectId())
            ?? throw new \LogicException('The lifecycle project does not exist.');
        $workflow = $this->users->workflow();

        foreach (self::SESSION_START_SLUGS as $slug => $title) {
            if ($this->documents->find(self::documentId($slug)) instanceof Document) {
                continue;
            }

            $document = new Document($context->devUser(), $project, $title, $context->at(1_200));
            $version = $document->addVersion(
                \sprintf("# %s\n\nA short product design that the workflow approved.\n", $title),
                \sprintf('<h1>%s</h1><p>A short product design that the workflow approved.</p>', $title),
            );
            $document->status = DocumentStatus::Approved;
            $this->tagApplier->apply($document, ['product-design']);
            $this->store($context, $document, $slug);

            $this->em->persist(new Review($version, Verdict::Approved, $workflow, submittedAt: $context->at(600), note: 'The design passed review.', workflowRule: 'product-design-approve'));
            $this->em->flush();
        }
    }

    private function seedHistoryComment(SeedContext $context, User $owner): void
    {
        $commentId = DevSeedId::of('review/comment/worker-run-history');
        if ($this->em->find(Comment::class, $commentId) instanceof Comment) {
            return;
        }

        $history = $this->documents->find(self::documentId('worker-run-history'))
            ?? throw new \LogicException('The worker run history document does not exist.');
        $comment = $this->comment($context, $history->currentVersion(), $owner, false);
        $context->ids->assign($comment, $commentId);
        $this->em->persist($comment);
        $this->em->flush();
    }

    private function seedHistoryReview(SeedContext $context): void
    {
        $history = $this->documents->find(self::documentId('worker-run-history'))
            ?? throw new \LogicException('The worker run history document does not exist.');
        $version = $history->versions->first();
        if (!$version instanceof DocumentVersion || null !== $this->reviews->findOneBy(['version' => $version])) {
            return;
        }

        $this->em->persist(new Review($version, Verdict::ChangesRequested, $context->devUser(), submittedAt: $context->at(500), note: 'Answer the failure question first.'));
        $this->em->flush();
    }

    private function seedApiKeyDocuments(SeedContext $context): void
    {
        $owner = $this->users->find(DevSeedId::of(DevAccountSeeder::KEYS_USER))
            ?? throw new \LogicException('The keys account does not exist.');
        $project = $this->projects->find(DevProjectSeeder::keysProjectId())
            ?? throw new \LogicException('The keys project does not exist.');

        foreach (['key-verdict' => 'Verdict recorded by a script', 'key-comment' => 'Comment written by a script', 'person-twin' => 'Verdict and comment by a person'] as $slug => $title) {
            if ($this->documents->find(self::documentId($slug)) instanceof Document) {
                continue;
            }

            $document = new Document($owner, $project, $title, $context->at(1_200));
            $version = $document->addVersion(
                \sprintf("# %s\n\nA short document for the verdict chip and the comment thread.\n", $title),
                \sprintf('<h1>%s</h1><p>A short document for the verdict chip and the comment thread.</p>', $title),
            );
            $document->status = DocumentStatus::Approved;
            $this->store($context, $document, $slug);

            $byKey = 'key-verdict' === $slug;
            $this->em->persist(new Review($version, Verdict::Approved, $owner, submittedAt: $context->at(600), note: 'Ready to build.', apiKeyId: $byKey ? self::apiKeyId() : null, apiKeyName: $byKey ? 'CI' : null));
            $this->em->persist($this->comment($context, $version, $owner, 'key-comment' === $slug));
            $this->em->flush();
        }
    }

    private function comment(SeedContext $context, DocumentVersion $version, User $author, bool $byKey): Comment
    {
        return new Comment(
            $version,
            $author,
            'Please keep this section short.',
            Anchor::unanchored(),
            createdAt: $context->at(300),
            apiKeyId: $byKey ? self::apiKeyId() : null,
            apiKeyName: $byKey ? 'CI' : null,
        );
    }

    private function store(SeedContext $context, Document $document, string $slug): void
    {
        $context->ids->assign($document, self::documentId($slug));
        $this->em->persist($document);
        $this->em->flush();
    }
}
