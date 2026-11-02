<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\FindTakenProjectSlugCommand;
use App\Module\Project\Command\FindTakenProjectSlugHandler;

/** Turns what a project handler refuses with into the coded problems of the public API. */
final readonly class ProjectApiProblems
{
    public function __construct(
        private FindTakenProjectSlugHandler $findTakenSlug,
    ) {
    }

    /**
     * A create refuses a name that is empty after slugging as `name_invalid`. Any other
     * refusal means the name is taken, whether by name or by the slug it gives.
     *
     * @param non-empty-string $name
     */
    public function forCreate(DomainErrors $errors, User $owner, string $name): ApiProblemException
    {
        if ('project.error.slug_empty' === ($errors->errors['name'] ?? null)) {
            return new ApiProblemException(new ApiProblem(422, 'name_invalid', 'Name invalid', ProjectErrorSentences::of('project.error.slug_empty')), $errors);
        }

        return $this->nameTaken($owner, $name, $errors);
    }

    /** @param ?string $name the new name, or null when the request leaves the name alone */
    public function forUpdate(DomainErrors $errors, User $owner, ?string $name): ApiProblemException
    {
        $nameError = $errors->errors['name'] ?? null;
        $trimmed = trim($name ?? '');
        if (1 === \count($errors->errors) && '' !== $trimmed) {
            if ('project.error.name_taken' === $nameError) {
                return $this->nameTaken($owner, $trimmed, $errors);
            }
            if ('project.error.slug_taken' === $nameError) {
                return new ApiProblemException(new ApiProblem(409, 'slug_taken', 'Slug taken', ProjectErrorSentences::of($nameError)), $errors);
            }
        }

        $fields = [];
        foreach ($errors->errors as $field => $key) {
            $fields[] = [
                'field' => $field,
                'code' => 'project.error.slug_empty' === $key ? 'name_invalid' : self::codeOf($field, $key),
                'message' => ProjectErrorSentences::of($key, $errors->parameters[$field] ?? []),
            ];
        }

        $problem = ApiProblemException::validationFailed($fields);

        return new ApiProblemException($problem->problem, $errors);
    }

    /** @param non-empty-string $name */
    private function nameTaken(User $owner, string $name, DomainErrors $errors): ApiProblemException
    {
        $slug = ($this->findTakenSlug)(new FindTakenProjectSlugCommand($owner, $name));

        return new ApiProblemException(new ApiProblem(
            409,
            'name_taken',
            'Name taken',
            'Another project of this owner already has this name.',
            extras: null === $slug ? [] : ['slug' => $slug],
        ), $errors);
    }

    /** The last segment of the translation key, led by the field when it does not start with it. */
    private static function codeOf(string $field, string $key): string
    {
        $last = substr($key, (int) strrpos($key, '.') + 1);
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));

        return str_starts_with($last, $snake) ? $last : $snake.'_'.$last;
    }
}
