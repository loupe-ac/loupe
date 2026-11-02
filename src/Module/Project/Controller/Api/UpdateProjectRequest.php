<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Api;

use App\Doctrine\SearchLanguage;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\SiteOrigins;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A partial change of a project. A field left out, or sent as null, keeps its value.
 * The handler checks every value, so a refusal reaches the client as one list.
 */
#[OA\Schema(description: 'A field you leave out keeps its value.')]
final readonly class UpdateProjectRequest
{
    public function __construct(
        #[OA\Property(description: 'A new name. It also changes the slug.', maxLength: Project::MAX_NAME_LENGTH, minLength: 1)]
        public ?string $name = null,

        #[OA\Property(description: 'An empty string clears it.', maxLength: Project::MAX_DESCRIPTION_LENGTH)]
        public ?string $description = null,

        #[OA\Property(description: 'An empty string clears it.', maxLength: Project::MAX_DOMAIN_LENGTH)]
        public ?string $domain = null,

        #[OA\Property(ref: new Model(type: SearchLanguage::class))]
        public ?SearchLanguage $searchLanguage = null,

        /** @var list<mixed>|null */
        #[OA\Property(description: 'Replaces the whole stored list, and an empty list clears it. At most 20 entries and 4000 characters together, with no line break in an entry.', type: 'array', items: new OA\Items(type: 'string'), maxItems: SiteOrigins::MAX)]
        public ?array $origins = null,
    ) {
    }
}
