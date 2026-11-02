<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\ToolCallErrorMessages;

/** Turns what a document handler refuses with into the coded problems of the public API. */
final readonly class DocumentApiProblems
{
    /** A refusal that names no field: the state of the document or of the series decides it. */
    private const array CONFLICTS = [
        'review.series.error.ordinal_taken' => ['series_ordinal_taken', 'Series ordinal taken', null],
        'review.series.error.name_taken' => ['series_name_taken', 'Series name taken', null],
        'review.publish.error.archived' => ['document_archived', 'Document archived', 'An archived document cannot be published. Restore it with a PATCH that sets archived to false first.'],
    ];

    /** The field and the code of a refusal that the client can correct. */
    private const array FIELDS = [
        'review.create.error.blank' => ['title', 'title_blank'],
        'review.rename.error.blank' => ['title', 'title_blank'],
        'review.create.error.too_long' => ['title', 'title_too_long'],
        'review.rename.error.too_long' => ['title', 'title_too_long'],
        'review.revise.error.description_blank' => ['description', 'description_blank'],
        'review.archive.error.reason_blank' => ['archiveReason', 'archive_reason_blank'],
        'review.tags.error.too_long' => ['tags', 'tag_too_long'],
        'review.series.error.name_required' => ['series', 'series_name_required'],
        'review.series.error.too_long' => ['series', 'series_too_long'],
        'review.series.error.ordinal_required' => ['seriesOrdinal', 'ordinal_required'],
        'review.series.error.ordinal_not_positive' => ['seriesOrdinal', 'ordinal_not_positive'],
        'review.series.error.ordinal_too_large' => ['seriesOrdinal', 'ordinal_too_large'],
        'review.references.error.self_reference' => ['references', 'self_reference'],
        'review.references.error.other_project' => ['references', 'other_project'],
        Document::MARKDOWN_TOO_LARGE => ['markdown', 'markdown_too_large'],
    ];

    public static function documentNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'document_not_found', 'Document not found', 'This project has no document with this id.'));
    }

    public static function versionNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'document_version_not_found', 'Document version not found', 'This document has no version with this number.'));
    }

    /**
     * The first refusal that is a conflict decides the answer. Any other refusal is a field
     * the client can correct, and they leave together as one list. A refusal that no code
     * covers stays a DomainErrors, so it is logged as a failure.
     */
    public function from(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        $fields = [];
        foreach ($errors->errors as $field => $key) {
            $sentence = ToolCallErrorMessages::sentence($key, $errors->parameters[$field] ?? []);
            if (isset(self::CONFLICTS[$key])) {
                [$code, $title, $detail] = self::CONFLICTS[$key];

                return new ApiProblemException(new ApiProblem(409, $code, $title, $detail ?? $sentence), $errors);
            }
            if (!isset(self::FIELDS[$key])) {
                return $errors;
            }
            [$name, $code] = self::FIELDS[$key];
            $fields[] = ['field' => $name, 'code' => $code, 'message' => $sentence];
        }

        return new ApiProblemException(ApiProblemException::validationFailed($fields)->problem, $errors);
    }
}
