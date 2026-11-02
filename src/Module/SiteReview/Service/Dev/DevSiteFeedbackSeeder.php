<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/** Writes four comments of the site-review widget: two pending, one addressed and one resolved. */
#[When('dev')]
#[When('test')]
final readonly class DevSiteFeedbackSeeder implements DevSeederInterface
{
    public const string KEY = 'site-feedback';

    /** @var list<string> the slugs, in the order the comments are written */
    public const array SLUGS = ['contrast', 'spacing', 'recovery', 'wording'];

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public static function commentId(string $slug): Uuid
    {
        return DevSeedId::of('site-review/comment/'.$slug);
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

        foreach (self::SLUGS as $slug) {
            if ($this->em->find(SiteReviewComment::class, self::commentId($slug)) instanceof SiteReviewComment) {
                continue;
            }

            $position = $this->siteReviewComments->nextPositionForProject($project);
            $comment = match ($slug) {
                'contrast' => $this->contrast($context, $project, $position),
                'spacing' => $this->spacing($context, $project, $position),
                'recovery' => $this->recovery($context, $project, $position),
                default => $this->wording($context, $project, $position),
            };
            $context->ids->assign($comment, self::commentId($slug));
            $this->em->persist($comment);
            $this->em->flush();
        }
    }

    private function contrast(SeedContext $context, Project $project, int $position): SiteReviewComment
    {
        $comment = new SiteReviewComment(
            project: $project,
            position: $position,
            body: 'The payment button needs stronger contrast against the summary.',
            url: 'https://atlas.example/checkout',
            createdAt: $context->at(1_680),
        );
        $comment->anchors->add(new SiteReviewCommentAnchor($comment, 1, 'button[data-action="pay"]', 'Complete your order'));

        return $comment;
    }

    private function spacing(SeedContext $context, Project $project, int $position): SiteReviewComment
    {
        $comment = new SiteReviewComment(
            project: $project,
            position: $position,
            body: 'Add more space between the items and the total in the order summary.',
            url: 'https://atlas.example/checkout',
            createdAt: $context->at(7_200),
        );
        $comment->anchors->add(new SiteReviewCommentAnchor($comment, 1, '.order-summary .items', 'Everyday notebook · $24'));
        $comment->anchors->add(new SiteReviewCommentAnchor($comment, 2, '.order-summary .total', 'Total · $28'));
        $comment->status = SiteReviewCommentStatus::Addressed;

        return $comment;
    }

    private function recovery(SeedContext $context, Project $project, int $position): SiteReviewComment
    {
        $comment = new SiteReviewComment(
            project: $project,
            position: $position,
            body: 'Explain that we keep the order when a payment fails.',
            url: 'https://atlas.example/checkout',
            createdAt: $context->at(14_400),
        );
        $comment->anchors->add(new SiteReviewCommentAnchor($comment, 1, '.payment-recovery', 'Your order is saved while we confirm your payment.'));

        return $comment;
    }

    private function wording(SeedContext $context, Project $project, int $position): SiteReviewComment
    {
        $comment = new SiteReviewComment(
            project: $project,
            position: $position,
            body: 'The empty basket reads as an error. Say what to do next instead.',
            url: 'https://atlas.example/basket',
            createdAt: $context->at(172_800),
        );
        $comment->status = SiteReviewCommentStatus::Resolved;

        return $comment;
    }
}
