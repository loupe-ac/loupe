<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Doctrine\SearchLanguage;
use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectRenamed;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\AllowedOriginsInput;
use App\Utils\Slug;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UpdateProjectHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(UpdateProjectCommand $command): Project
    {
        $project = $command->project;

        // A null input keeps the stored value, so only a given input is checked: a
        // stored value over a limit must not block an unrelated change.
        $errors = [];
        $parameters = [];

        $name = null === $command->name ? $project->name : trim($command->name);
        if ('' === $name) {
            $errors['name'] = 'project.error.name_blank';
        } elseif (null !== $command->name && mb_strlen($name) > Project::MAX_NAME_LENGTH) {
            $errors['name'] = 'project.error.name_too_long';
        }

        $domain = null === $command->domain ? $project->domain : self::cleared($command->domain);
        if (null !== $command->domain && mb_strlen($domain ?? '') > Project::MAX_DOMAIN_LENGTH) {
            $errors['domain'] = 'project.error.domain_too_long';
        }

        $description = null === $command->description ? $project->description : self::cleared($command->description);
        if (null !== $command->description && mb_strlen($description ?? '') > Project::MAX_DESCRIPTION_LENGTH) {
            $errors['description'] = 'project.error.description_too_long';
        }

        $searchLanguage = $project->searchLanguage;
        if ($command->searchLanguage instanceof SearchLanguage) {
            $searchLanguage = $command->searchLanguage;
        } elseif (null !== $command->searchLanguage) {
            $searchLanguage = SearchLanguage::tryFrom($command->searchLanguage);
            if (null === $searchLanguage) {
                $errors['searchLanguage'] = 'project.error.search_language_unknown';
                $parameters['searchLanguage'] = ['%language%' => $command->searchLanguage, '%languages%' => implode(', ', SearchLanguage::values())];
                $searchLanguage = $project->searchLanguage;
            }
        }

        $origins = $project->allowedOrigins;
        if (null !== $command->origins) {
            try {
                $origins = AllowedOriginsInput::normalise($command->origins);
            } catch (DomainErrors $e) {
                $errors += $e->errors;
            }
        }

        if ([] !== $errors) {
            throw new DomainErrors($errors, $parameters);
        }

        // The (owner, name) pair is unique. A rename may only collide with a *different*
        // project — renaming to the same name (or only editing the domain) is fine.
        $existing = $this->projects->findOneByOwnerAndName($project->owner, $name);
        if (null !== $existing && $existing !== $project) {
            throw new DomainErrors(['name' => 'project.error.name_taken']);
        }

        // Derived on every save, not only on a rename: an image older than the slug
        // column can leave a slug empty, or stale after its own rename. A suffixed
        // slug stays only while another project holds the plain one.
        $slug = Slug::forName($name, $project->slug);
        if ('' === $slug) {
            throw new DomainErrors(['name' => 'project.error.slug_empty']);
        }
        $plain = Slug::fromName($name);
        if ($slug !== $plain && \in_array($this->projects->findOneByOwnerAndSlug($project->owner, $plain), [null, $project], true)) {
            $slug = $plain;
        }
        $holder = $this->projects->findOneByOwnerAndSlug($project->owner, $slug);
        if (null !== $holder && $holder !== $project) {
            throw new DomainErrors(['name' => 'project.error.slug_taken']);
        }

        // Read before the name is assigned, because the name's set hook rewrites the slug.
        $fromSlug = $project->slug;
        $project->name = $name;
        $project->slug = $slug;
        $project->domain = $domain;
        $project->description = $description;
        // Documents keep the language they were written with, so this only
        // changes what a document created after it inherits.
        $project->searchLanguage = $searchLanguage;
        $project->allowedOrigins = $origins;
        // Before the flush, so a listener's rows commit or roll back with the save.
        // A null slug is no rename: no rule file can name a project that had none.
        if (null !== $fromSlug && $fromSlug !== $slug) {
            $this->events->dispatch(new ProjectRenamed($project, $fromSlug, $slug, $command->actor));
        }
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent rename won the race with the checks above, and a unique index caught it.
            throw new DomainErrors(['name' => str_contains($e->getMessage(), Project::SLUG_CONSTRAINT) ? 'project.error.slug_taken' : 'project.error.name_taken']);
        }

        $this->auditor->record(
            'project.updated',
            AuditOutcome::Success,
            [
                'projectId' => (string) $project->id,
                // Whose project it is, not who acted — the Auditor resolves the
                // actor itself. The two coincide only while a project is
                // editable by its owner alone.
                'ownerId' => (string) $project->owner->id,
            ],
            new AuditSubject('project', (string) $project->id),
        );

        return $project;
    }

    private static function cleared(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
