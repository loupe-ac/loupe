<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Problem\ApiProblemException;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Uid\Uuid;

final readonly class PageProjectsHandler
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private ProjectRepository $projects,
    ) {
    }

    /**
     * @return Page<Project>
     *
     * @throws ApiProblemException `invalid_cursor` for a cursor whose position does not parse
     */
    public function __invoke(PageProjectsCommand $command): Page
    {
        $newestFirst = $command->sort->newestFirst();
        $rows = $this->projects->findPageByOwner(
            $command->owner,
            $command->only,
            $command->page->fetchSize(),
            $newestFirst,
            null === $command->page->cursor ? null : self::positionOf($command->page->cursor),
        );

        return Page::fromRows($rows, $command->page, fn (Project $project): Cursor => new Cursor(
            $command->sort->value,
            $project->createdAt->format(self::TIME_FORMAT),
            (string) $project->id,
        ));
    }

    /** @return array{\DateTimeImmutable, Uuid} */
    private static function positionOf(Cursor $cursor): array
    {
        $at = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $cursor->sortValue);
        if (false === $at || !Uuid::isValid($cursor->id)) {
            throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
        }

        return [$at, Uuid::fromString($cursor->id)];
    }
}
