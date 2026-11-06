<?php

declare(strict_types=1);

namespace App\Module\Insights\Admin;

use Ubermuda\AdminBundle\Menu\AdminMenuItemInterface;

final class PublicLinksMenuItem implements AdminMenuItemInterface
{
    #[\Override]
    public function getLabel(): string
    {
        // The bundle's sidebar template has no translator, so labels stay raw.
        return 'Public links'; // @translation-check-ignore
    }

    #[\Override]
    public function getIcon(): string
    {
        return 'link';
    }

    #[\Override]
    public function getRouteName(): string
    {
        return 'app_admin_public_links_list';
    }

    #[\Override]
    public function getActiveRoutePrefix(): string
    {
        return 'app_admin_public_links_';
    }

    #[\Override]
    public function getPriority(): int
    {
        return 30;
    }
}
