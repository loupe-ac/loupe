<?php

declare(strict_types=1);

namespace App\Module\Workflow\Controller\Api;

use App\Module\Workflow\Command\WorkflowSettingView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** The resolved value of one workflow setting, for one repository or for the whole project. */
#[OA\Schema(required: ['key', 'scope', 'repository', 'value', 'source', 'warnings'])]
final readonly class WorkflowSettingResponse
{
    /**
     * @param string|bool|list<string>|null $value
     * @param list<string>                  $warnings
     */
    public function __construct(
        #[OA\Property(description: 'The key of the setting.')]
        public string $key,

        #[OA\Property(description: '`repository` for a setting that each repository states, or `project` for one value of the whole project.', enum: ['repository', 'project'])]
        public string $scope,

        #[OA\Property(ref: new Model(type: WorkflowRepositoryResponse::class), description: 'The repository of a repository setting. Null for a project setting.', nullable: true)]
        public ?WorkflowRepositoryResponse $repository,

        #[OA\Property(description: 'The value, or null when the setting has none. It is a string, a boolean or a list of strings, as the template declares the setting.', nullable: true, oneOf: [
            new OA\Schema(type: 'string'),
            new OA\Schema(type: 'boolean'),
            new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
        ])]
        public string|bool|array|null $value,

        #[OA\Property(description: 'Where the value came from: `file` (a valid value in .loupe.yaml), `invalid` (the file holds a value that is not allowed, so the value is null), `stored` (a value the app keeps for the repository until the file states the key), `default` (the default of the template), `unset` (no source states a value), `conflict` (two repositories state different values) or `template` (the value of the project copy of the template). Only a project setting has `conflict` and `template`.')]
        public string $source,

        #[OA\Property(description: 'The lines that name a problem with the key.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $warnings,
    ) {
    }

    public static function fromView(WorkflowSettingView $setting): self
    {
        return new self(
            $setting->key,
            $setting->scope->value,
            null === $setting->repository ? null : new WorkflowRepositoryResponse($setting->repository->path, $setting->repository->forge),
            $setting->value,
            $setting->source->value,
            $setting->warnings,
        );
    }
}
