<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Service\InboxReviewTarget;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxReviewTargetTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private InboxReviewTarget $target;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $target = self::getContainer()->get(InboxReviewTarget::class);
        self::assertInstanceOf(InboxReviewTarget::class, $target);
        $this->target = $target;
    }

    public function test_a_document_review_names_the_document(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'target-doc'), 'target-doc');
        $document = $this->document($this->em, $project);
        $review = $this->review($project, $document);

        self::assertSame('Document "'.$document->title.'"', $this->target->label($review));
    }

    public function test_a_pull_request_review_names_the_number_and_the_stored_title(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'target-pr'), 'target-pr');
        $link = new CardPullRequest($this->card($this->em, $project), 'https://github.com/Acme/Widgets/pull/812', forge: Forge::GitHub, repository: 'Acme/Widgets', number: 812);
        $row = new ForgePullRequest($project, 'github', 'acme/widgets', 812);
        $row->title = 'Fix the board filter';
        $this->em->persist($row);
        $this->em->flush();

        self::assertSame('Pull request #812: Fix the board filter', $this->target->label($this->review($project, $link)));
    }

    public function test_a_pull_request_with_no_stored_title_reads_the_number_alone(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'target-untitled'), 'target-untitled');
        $link = new CardPullRequest($this->card($this->em, $project), 'https://github.com/acme/widgets/pull/9', forge: Forge::GitHub, repository: 'acme/widgets', number: 9);
        $this->em->persist(new ForgePullRequest($project, 'github', 'acme/widgets', 9));
        $this->em->flush();

        self::assertSame('Pull request #9', $this->target->label($this->review($project, $link)));
    }

    public function test_a_review_whose_target_link_is_gone_reads_its_stored_label(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'target-gone'), 'target-gone');
        $link = new CardPullRequest($this->card($this->em, $project), 'https://example.com/odd/link');
        $review = $this->review($project, $link);
        $review->pullRequest = null;

        self::assertSame('https://example.com/odd/link', $this->target->label($review));
    }

    private function review(Project $project, Document|CardPullRequest $target): InboxReview
    {
        $item = new InboxItem($project, 1, InboxItemKind::Review, 'Please review', false, InboxAskOrigin::Agent);
        $review = new InboxReview($item, $target);
        foreach ([$target, $item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return $review;
    }
}
