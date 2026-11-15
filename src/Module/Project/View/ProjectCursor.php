<?php

declare(strict_types=1);

namespace App\Module\Project\View;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * A place in the projects list, newest first: the creation time and the id of
 * a project. A list that loaded at a cursor counts the projects after it, in
 * the order the list sorts by. An owner with no project has the empty cursor.
 */
final readonly class ProjectCursor
{
    private function __construct(
        public ?\DateTimeImmutable $createdAt,
        public ?Uuid $id,
    ) {
    }

    public static function of(?Project $project): self
    {
        return new self($project?->createdAt, $project?->id);
    }

    public static function parse(string $value): self
    {
        [$time, $id] = array_pad(explode('|', $value, 2), 2, '');
        try {
            $createdAt = new \DateTimeImmutable($time);
        } catch (\Exception) {
            return new self(null, null);
        }

        return Uuid::isValid($id) ? new self($createdAt, Uuid::fromString($id)) : new self(null, null);
    }

    public function toString(): string
    {
        if (null === $this->createdAt || null === $this->id) {
            return '';
        }

        return $this->createdAt->format(\DATE_ATOM).'|'.$this->id->toRfc4122();
    }
}
