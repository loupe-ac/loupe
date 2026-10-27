<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;

/** What one repository row of the Repositories section draws. */
final readonly class RepositoryRow
{
    /**
     * @param string|null               $workflowLabelKey the translation key of the workflow name, null when the project runs no known workflow
     * @param ForgeRepositoryCheck|null $check            the stored check, null before the first read
     * @param list<RepositoryCheckLine> $lines
     * @param bool                      $suggests         whether the workflow suggests any setting, so a check applies at all
     * @param bool                      $checking         whether a read that the page queued has not answered yet
     * @param RepositoryGuide           $guide            the steps to fix the gaps of the stored check
     */
    public function __construct(
        public ForgeRepository $repository,
        public string $health,
        public ?string $workflowLabelKey,
        public ?ForgeRepositoryCheck $check,
        public array $lines,
        public bool $suggests,
        public bool $checking,
        public RepositoryGuide $guide,
    ) {
    }

    public function elementId(): string
    {
        return self::elementIdOf($this->repository);
    }

    public function blocks(): bool
    {
        return null !== $this->check && ForgeRepositoryCheck::countBlockingGaps($this->check->items) > 0;
    }

    public static function elementIdOf(ForgeRepository $repository): string
    {
        return 'forge-repository-'.$repository->id;
    }
}
