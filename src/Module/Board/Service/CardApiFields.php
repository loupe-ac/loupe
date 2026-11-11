<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Api\Problem\ApiProblemException;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardTypeCatalog;

/** Resolves the fields of a card write that name a column, a card type or a reporter, and refuses the ones that name nothing. */
final readonly class CardApiFields
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private CardTypeCatalog $catalog,
    ) {
    }

    /**
     * @return array{column: ?BoardColumn, type: ?string} null for a field that the write leaves out
     *
     * @throws ApiProblemException `validation_failed` naming each field that names nothing the project has
     */
    public function resolve(Project $project, ?string $status, ?string $type): array
    {
        $errors = [];
        $column = null;
        if (null !== $status) {
            $column = $this->boardColumns->findOneByProjectAndSlug($project, $status);
            if (null === $column) {
                $errors[] = ['field' => 'status', 'code' => 'unknown_status', 'message' => \sprintf('This board has no column "%s".', $status)];
            }
        }
        if (null !== $type && !$this->catalog->forProject($project->requireId())->has($type)) {
            $errors[] = ['field' => 'type', 'code' => 'unknown_type', 'message' => \sprintf('This project declares no card type "%s".', $type)];
        }
        if ([] !== $errors) {
            throw ApiProblemException::validationFailed($errors);
        }

        return ['column' => $column, 'type' => $type];
    }

    /**
     * @throws ApiProblemException `validation_failed` when the board has no column with that slug
     */
    public function column(Project $project, string $slug, string $field): BoardColumn
    {
        return $this->boardColumns->findOneByProjectAndSlug($project, $slug)
            ?? throw ApiProblemException::validationFailed([['field' => $field, 'code' => 'unknown_column', 'message' => \sprintf('This board has no column "%s".', $slug)]]);
    }

    /**
     * A key records a card as raised by the key, or by a person it writes down. A CLI sign-in records an agent,
     * or a person it writes down. The credential decides the default.
     *
     * @throws ApiProblemException `validation_failed` for a reporter that the credential may not claim
     */
    public function reporter(Actor $actor, ?string $claimed): Actor
    {
        $allowed = [Actor::Human, Actor::ApiKey === $actor ? Actor::ApiKey : Actor::Agent];
        if (null === $claimed) {
            return $allowed[1];
        }
        $reporter = Actor::tryFrom($claimed);
        if (null === $reporter || !\in_array($reporter, $allowed, true)) {
            throw ApiProblemException::validationFailed([['field' => 'reporter', 'code' => 'reporter_invalid', 'message' => \sprintf('This credential may pass %s.', implode(' or ', array_map(static fn (Actor $case): string => '`'.$case->value.'`', $allowed)))]]);
        }

        return $reporter;
    }
}
