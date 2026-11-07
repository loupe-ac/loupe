<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\View\ListPublicLinksView;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AdminBundle\Listing\ListPagePagination;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** Reads the admin list of projects that have public links. A project an admin took down comes first. */
final readonly class ListPublicLinksHandler
{
    public const int PER_PAGE = 20;
    public const array ALLOWED_SORTS = ['project', 'owner', 'items'];

    public function __construct(
        private PublicLinkRepository $publicLinks,
        private ListPagePagination $pagination,
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function __invoke(ListPublicLinksCommand $command): ListPublicLinksView
    {
        $userId = null !== $command->userId && Uuid::isValid($command->userId) ? $command->userId : null;
        $query = trim($command->query ?? '') ?: null;
        $filters = array_filter(['q' => $query, 'user' => $userId], static fn (?string $value): bool => null !== $value);

        $total = $this->publicLinks->countProjects($query, $userId);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $clampedPage = $this->pagination->clampPage('public_links', $command->page, $total, self::PER_PAGE, $filters);

        return new ListPublicLinksView(
            null === $clampedPage ? $this->publicLinks->findProjectRows($command->page, self::PER_PAGE, $command->sort, $command->dir, $query, $userId) : [],
            $total,
            $totalPages,
            $this->pagination->buildPageList($command->page, $totalPages),
            $filters,
            $clampedPage,
            $this->featureFlags->isEnabled(PublicLink::FLAG, true),
        );
    }
}
