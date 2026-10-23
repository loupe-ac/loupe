<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\Entity\InboxReview;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The line that names what an agent's review ask is about: a document, or a pull request with its stored title. */
final readonly class InboxReviewTarget
{
    public function __construct(
        private ForgePullRequestRepository $forgePullRequests,
        private TranslatorInterface $translator,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    /** The stored label of the ask stands in when its document or pull request link is gone. */
    public function label(InboxReview $review): string
    {
        $document = $review->document;
        if (null !== $document) {
            return $this->translator->trans('inbox.review.target.document', ['%title%' => $document->title], null, $this->locale);
        }

        $link = $review->pullRequest;
        if (null === $link || null === $link->repository || null === $link->number) {
            return $review->targetLabel;
        }

        $projectId = $review->item->project->id ?? throw new \LogicException('A stored inbox item has a project id.');
        $title = $this->forgePullRequests->findByKeys($projectId, [['forge' => $link->forge->value, 'repository' => $link->repository, 'number' => $link->number]])[0]->title ?? null;
        $params = ['%number%' => $link->number, '%title%' => $title ?? ''];

        return $this->translator->trans(null === $title || '' === $title ? 'inbox.review.target.pull_request_untitled' : 'inbox.review.target.pull_request', $params, null, $this->locale);
    }
}
