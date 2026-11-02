<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Api;

use App\Doctrine\SearchLanguage;
use App\Module\Project\Entity\Project;
use OpenApi\Attributes as OA;

/** A project, as the public API shows it. */
#[OA\Schema(required: ['id', 'slug', 'name', 'description', 'domain', 'searchLanguage', 'allowedOrigins', 'createdAt'])]
final readonly class ProjectResponse
{
    /** @param list<string> $allowedOrigins */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property(description: 'Null on a project created before the slug existed, so quote the id.', nullable: true)]
        public ?string $slug,

        #[OA\Property(maxLength: Project::MAX_NAME_LENGTH)]
        public string $name,

        #[OA\Property(description: 'What the project is for.', nullable: true, maxLength: Project::MAX_DESCRIPTION_LENGTH)]
        public ?string $description,

        #[OA\Property(description: 'The domain of the site the project reviews.', nullable: true, maxLength: Project::MAX_DOMAIN_LENGTH)]
        public ?string $domain,

        #[OA\Property(description: 'The language a new document or inbox item of the project takes.')]
        public SearchLanguage $searchLanguage,

        #[OA\Property(description: 'The site origins the sign-in widget may run on.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $allowedOrigins,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,
    ) {
    }

    public static function fromProject(Project $project): self
    {
        return new self(
            id: (string) $project->id,
            slug: $project->slug,
            name: $project->name,
            description: $project->description,
            domain: $project->domain,
            searchLanguage: $project->searchLanguage,
            allowedOrigins: $project->allowedOrigins,
            createdAt: $project->createdAt->format(\DATE_ATOM),
        );
    }
}
