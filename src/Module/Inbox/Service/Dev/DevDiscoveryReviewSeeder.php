<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Admin\Service\Dev\DevFeatureFlagSeeder;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds a discovery report that waits for review, with its inbox item, in the set-up project. It writes the item
 * directly, so it exists whatever inbox.enabled says. The empty live board project has no inbox item.
 */
#[When('dev')]
#[When('test')]
final readonly class DevDiscoveryReviewSeeder implements DevSeederInterface
{
    public const string KEY = 'inbox-discovery-review';

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private DocumentRepository $documents,
        private DiscoveryRunRepository $discoveryRuns,
        private InboxItemRepository $inboxItems,
        private InboxReviewRepository $inboxReviews,
        private InboxSearchIndexer $searchIndexer,
    ) {
    }

    public static function runId(): Uuid
    {
        return DevSeedId::of('inbox/discovery-review/run');
    }

    public static function documentId(): Uuid
    {
        return DevSeedId::of('inbox/discovery-review/report');
    }

    public static function itemId(): Uuid
    {
        return DevSeedId::of('inbox/discovery-review/item');
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY, DevFeatureFlagSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::setupDoneProjectId())
            ?? throw new \LogicException('The set-up project does not exist.');
        $report = $this->report($context, $project);
        $this->run($context, $project, $report);

        $item = $this->inboxItems->find(self::itemId()) ?? $this->item($context, $project);
        // Each part is found or written on its own, so a re-seed restores whichever one is missing.
        if (!$item->documents->exists(static fn (int $key, InboxItemDocument $link): bool => $link->document === $report)) {
            $link = new InboxItemDocument($item, $report, $item->createdAt);
            $item->documents->add($link);
            $this->em->persist($link);
        }
        if (null === $this->inboxReviews->findOneBy(['item' => $item])) {
            $this->em->persist(new InboxReview($item, $report));
        }
        $this->em->flush();
        $this->searchIndexer->index($item);
    }

    private function item(SeedContext $context, Project $project): InboxItem
    {
        $item = new InboxItem(
            project: $project,
            number: $this->inboxItems->nextNumber($project),
            kind: InboxItemKind::Review,
            title: 'Review the readiness report',
            blocking: false,
            origin: InboxAskOrigin::Loupe,
            createdAt: $context->at(615),
            searchLanguage: $project->searchLanguage,
        );
        $item->discoveryRunId = self::runId();
        $context->ids->assign($item, self::itemId());
        $this->em->persist($item);

        return $item;
    }

    private function report(SeedContext $context, Project $project): Document
    {
        $report = $this->documents->find(self::documentId());
        if ($report instanceof Document) {
            return $report;
        }

        $report = new Document($context->devUser(), $project, 'Readiness report', $context->at(630));
        $report->addVersion(
            "# Readiness report\n\nThe repository has a build command and a test command.\n",
            '<h1>Readiness report</h1><p>The repository has a build command and a test command.</p>',
        );
        $context->ids->assign($report, self::documentId());
        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    private function run(SeedContext $context, Project $project, Document $report): void
    {
        if ($this->discoveryRuns->find(self::runId()) instanceof DiscoveryRun) {
            return;
        }

        $run = new DiscoveryRun($project, $context->at(900));
        $run->state = DiscoveryRunState::Reported;
        $run->reportDocument = $report;
        $context->ids->assign($run, self::runId());
        $this->em->persist($run);
        $this->em->flush();
    }
}
