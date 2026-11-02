<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Service\DocumentReferenceValidator;

/**
 * Changes the metadata of a document in one call: title, tags, references, series and
 * archive state. No version is created.
 *
 * It checks every field before it writes any, so a refused field leaves the document as
 * it was. The series goes first among the writes because the series ordinal is the one
 * rule that only the database settles.
 */
final readonly class UpdateDocumentHandler
{
    public function __construct(
        private RenameDocumentHandler $rename,
        private SetDocumentTagsHandler $setTags,
        private SetDocumentSeriesHandler $setSeries,
        private SetDocumentReferencesHandler $setReferences,
        private ArchiveDocumentHandler $archive,
        private UnarchiveDocumentHandler $unarchive,
        private DocumentReferenceValidator $referenceValidator,
    ) {
    }

    public function __invoke(UpdateDocumentCommand $command): Document
    {
        $document = $command->document;
        $this->check($command);

        if ($command->placesInSeries) {
            ($this->setSeries)(new SetDocumentSeriesCommand($document, $command->seriesName, $command->seriesOrdinal));
        }
        if (null !== $command->title) {
            ($this->rename)(new RenameDocumentCommand($document, $command->title));
        }
        if (null !== $command->tagNames) {
            ($this->setTags)(new SetDocumentTagsCommand($document, $command->tagNames));
        }
        if (null !== $command->references) {
            ($this->setReferences)(new SetDocumentReferencesCommand($document, $command->references));
        }
        if (true === $command->archived) {
            ($this->archive)(new ArchiveDocumentCommand($document, $command->archiveReason));
        } elseif (false === $command->archived) {
            ($this->unarchive)(new UnarchiveDocumentCommand($document));
        }

        return $document;
    }

    /** @throws DomainErrors with every refused field */
    private function check(UpdateDocumentCommand $command): void
    {
        $errors = [];
        $parameters = [];
        $collect = static function (\Closure $check) use (&$errors, &$parameters): void {
            try {
                $check();
            } catch (DomainErrors $found) {
                $errors += $found->errors;
                $parameters += $found->parameters;
            }
        };

        if (null !== $command->title) {
            $title = trim($command->title);
            if ('' === $title) {
                $errors['title'] = 'review.rename.error.blank';
            } elseif (mb_strlen($title) > Document::MAX_TITLE_LENGTH) {
                $errors['title'] = 'review.rename.error.too_long';
            }
        }
        if (null !== $command->tagNames) {
            $collect(static fn () => Tag::normalizeNames($command->tagNames));
        }
        if (null !== $command->references) {
            $collect(fn () => $this->referenceValidator->validated($command->document->project, $command->document, $command->references));
        }
        if ($command->placesInSeries) {
            $collect(static fn () => Series::normalizePlacement($command->seriesName, $command->seriesOrdinal));
        }
        if (true === $command->archived && '' === trim($command->archiveReason ?? '')) {
            $errors['archiveReason'] = 'review.archive.error.reason_blank';
        }

        if ([] !== $errors) {
            throw new DomainErrors($errors, $parameters);
        }
    }
}
