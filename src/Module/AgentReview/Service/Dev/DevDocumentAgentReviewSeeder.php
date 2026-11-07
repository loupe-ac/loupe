<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\Dev\DevDocumentSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/** Writes two agent review rounds on the first seeded document: a failed one that a fix round answered, then a passed one. */
#[When('dev')]
#[When('test')]
final readonly class DevDocumentAgentReviewSeeder implements DevSeederInterface
{
    public const string KEY = 'document-agent-reviews';

    public const string DOCUMENT_SLUG = 'first-handoff';

    public function __construct(
        private EntityManagerInterface $em,
        private DocumentRepository $documents,
        private CardDocumentRepository $cardDocuments,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevDocumentSeeder::KEY, 'inbox'];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $document = $this->documents->find(DevDocumentSeeder::documentId(self::DOCUMENT_SLUG));
        $card = null === $document ? null : array_first($this->cardDocuments->findForDocument($document))?->card;
        if (null === $document || null === $card) {
            return;
        }

        $rounds = [
            'failed' => new DocumentAgentReview(
                project: $card->project,
                card: $card,
                document: $document,
                versionNumber: 1,
                summary: 'The first five minutes skip the account setup.',
                conclusion: AgentReviewConclusion::Failure,
                findings: [new AgentReviewFinding(null, null, null, AgentReviewSeverity::Important, 'The guide skips the account setup', 'A reader who has no account cannot move a card to Ready.', AgentReviewCategory::Spec)],
                createdAt: $context->at(1_200),
            ),
            'passed' => new DocumentAgentReview(
                project: $card->project,
                card: $card,
                document: $document,
                versionNumber: 1,
                summary: 'The guide now starts with the account setup.',
                conclusion: AgentReviewConclusion::Success,
                findings: [],
                createdAt: $context->at(600),
            ),
        ];
        $rounds['failed']->answers = [['index' => 0, 'answer' => 'fixed', 'reason' => 'The guide opens with the account setup.']];
        $rounds['failed']->answeredAt = $context->at(900);

        foreach ($rounds as $slug => $round) {
            $id = DevSeedId::of('agent-review/document/'.self::DOCUMENT_SLUG.'/'.$slug);
            if ($this->em->find(DocumentAgentReview::class, $id) instanceof DocumentAgentReview) {
                continue;
            }
            $context->ids->assign($round, $id);
            $this->em->persist($round);
        }
        $this->em->flush();
    }
}
