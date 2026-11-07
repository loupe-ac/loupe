<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Insights\Repository\PublicLinkRepository;

/** The public links of the projects the user owns, with the note, which is text that the user wrote. */
final readonly class PublicLinksExporter implements UserDataExporterInterface
{
    public function __construct(
        private PublicLinkRepository $publicLinks,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'insights_public_links.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->publicLinks->findByOwner($user) as $link) {
            yield [
                'projectId' => (string) $link->project->id,
                'project' => $link->project->name,
                'kind' => $link->kind->value,
                'item' => $link->savedGraph->name ?? $link->experiment,
                'code' => $link->code,
                'enabled' => $link->enabled,
                'note' => $link->note,
            ];
        }
    }
}
