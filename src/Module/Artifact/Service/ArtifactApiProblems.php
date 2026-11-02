<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Turns what an artifact handler refuses with into the coded problems of the public API. */
final readonly class ArtifactApiProblems
{
    private const string KEY_PREFIX = 'artifact.error.';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function artifactNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'artifact_not_found', 'Artifact not found', 'This project has no such artifact.'));
    }

    public function versionNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'artifact_version_not_found', 'Artifact version not found', 'This artifact has no version with this number.'));
    }

    public function fileNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'artifact_file_not_found', 'Artifact file not found', 'This version of the artifact has no file at this path.'));
    }

    /** The answer for a switched-off feature: no route answers the path. */
    public function routeNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'route_not_found', 'Route not found', 'No route answers this path.'));
    }

    /** @return list<array{field: string, code: string, message: string}> */
    public function pathsMismatch(): array
    {
        return [['field' => 'paths', 'code' => 'paths_mismatch', 'message' => $this->sentence('artifact.error.paths_mismatch')]];
    }

    /** @return list<array{field: string, code: string, message: string}> */
    public function cardNotFound(): array
    {
        return [['field' => 'card', 'code' => 'card_not_found', 'message' => $this->sentence('artifact.error.card_not_found')]];
    }

    /**
     * A refusal of a store. A race that the route already checked, such as a project or an
     * artifact that went, answers like the check. Every other key is a field the client can correct.
     */
    public function forStore(DomainErrors $errors): ApiProblemException
    {
        $fields = [];
        foreach ($errors->errors as $field => $key) {
            $problem = match ($field) {
                'project' => ApiProblemException::projectNotFound(),
                'artifactId' => $this->artifactNotFound(),
                'artifacts' => $this->routeNotFound(),
                default => null,
            };
            if (null !== $problem) {
                return new ApiProblemException($problem->problem, $errors);
            }

            $fields[] = [
                'field' => $field,
                'code' => str_starts_with($key, self::KEY_PREFIX) ? substr($key, \strlen(self::KEY_PREFIX)) : $field.'_invalid',
                'message' => $this->sentence($key),
            ];
        }

        return new ApiProblemException(ApiProblemException::validationFailed($fields)->problem, $errors);
    }

    private function sentence(string $key): string
    {
        return $this->translator->trans($key, [], null, 'en');
    }
}
