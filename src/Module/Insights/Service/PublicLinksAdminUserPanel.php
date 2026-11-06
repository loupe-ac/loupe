<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Account\Admin\AdminUserPanel;
use App\Module\Account\Admin\AdminUserPanelInterface;
use App\Module\Account\Entity\User;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\View\PublicLinkProjectRow;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Insights' section on the admin user detail page: the public links of the projects the user owns, with a link
 * to the full list. The panel abstains for a user who owns no link. The context carries scalars only, so
 * Account never learns an Insights type.
 */
#[AsTaggedItem(priority: 3)]
final readonly class PublicLinksAdminUserPanel implements AdminUserPanelInterface
{
    private const int LIMIT = 10;

    public function __construct(
        private PublicLinkRepository $publicLinks,
    ) {
    }

    #[\Override]
    public function panelFor(User $user): ?AdminUserPanel
    {
        $userId = (string) $user->id;
        $total = $this->publicLinks->countProjects(null, $userId);
        if (0 === $total) {
            return null;
        }

        $projects = array_map(static fn (PublicLinkProjectRow $row): array => [
            'name' => $row->projectName,
            'items' => $row->items,
            'liveItems' => $row->liveItems,
            'takenDown' => $row->isTakenDown(),
        ], $this->publicLinks->findProjectRows(1, self::LIMIT, 'project', 'asc', null, $userId));

        return new AdminUserPanel('@Insights/admin/public_links_panel.html.twig', [
            'userId' => $userId,
            'projects' => $projects,
            'total' => $total,
        ]);
    }
}
