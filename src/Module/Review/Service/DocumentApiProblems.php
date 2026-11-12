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
    /**
     * A refusal that names no field: the state of the document, of the series or of the review decides it.
     * A null detail takes the sentence of the refusal.
     */
    private const array CONFLICTS = [
        'review.series.error.ordinal_taken' => [409, 'series_ordinal_taken', 'Series ordinal taken', null],
        'review.series.error.name_taken' => [409, 'series_name_taken', 'Series name taken', null],
        'review.publish.error.archived' => [409, 'document_archived', 'Document archived', 'An archived document cannot be published. Restore it with a PATCH that sets archived to false first.'],
        'review.document.flash.verdict_stale' => [409, 'verdict_stale', 'Verdict stale', 'The document has a newer version. Show it to the owner, then call again with its versionNumber.'],
        'review.document.flash.verdict_changed' => [409, 'verdict_changed', 'Verdict changed', 'The review changed since you read it. Read the review again, and pass its newest verdictId.'],
        'review.document.flash.decisions_unanswered' => [409, 'decisions_unanswered', 'Decisions unanswered', 'One or more decisions have no answer yet. Answer them first, or request changes.'],
        'review.document.flash.verdict_none' => [409, 'verdict_none', 'No verdict', 'The document has no verdict to withdraw.'],
        'review.document.flash.verdict_already_withdrawn' => [409, 'verdict_already_withdrawn', 'Verdict already withdrawn', 'The verdict is already withdrawn.'],
        'review.decision.error.stale_version' => [409, 'decision_stale', 'Decision stale', 'The document has a newer version. Show it to the owner, then call again with its versionNumber.'],
        'review.decision.error.unknown' => [404, 'decision_not_found', 'Decision not found', 'This version of the document holds no decision with that id.'],
        'comment.error.not_found' => [404, 'comment_not_found', 'Comment not found', 'This document has no such comment.'],
        'review.document.comment.error.stale_version' => [409, 'comment_version_stale', 'Comment version stale', 'This comment belongs to an older version. Read the review for the current comment ids.'],
        'comment.error.deleted' => [409, 'comment_deleted', 'Comment deleted', 'This thread is deleted. The owner must restore it first.'],
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
        'review.decision.error.unknown_version' => ['versionNumber', 'unknown_version'],
        'review.decision.error.unknown_option' => ['optionIndexes', 'unknown_option'],
        'review.form.save_decision_answer_form.note.too_long' => ['note', 'note_too_long'],
        'review.document.flash.verdict_invalid' => ['verdict', 'verdict_invalid'],
        'review.document.flash.note_or_comment_required' => ['note', 'note_or_comment_required'],
        'comment.error.reply_empty' => ['body', 'reply_empty'],
        'comment.error.reply_too_long' => ['body', 'reply_too_long'],
        'comment.error.reply_to_reply' => ['body', 'reply_to_reply'],
    ];

    public static function documentNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'document_not_found', 'Document not found', 'This project has no document with this id.'));
    }

    public static function seriesNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'series_not_found', 'Series not found', 'This project has no series with this name.'));
    }

    public static function versionNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'document_version_not_found', 'Document version not found', 'This document has no version with this number.'));
    }

    public static function commentNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'comment_not_found', 'Comment not found', 'This document has no such comment.'));
    }

    /** A comment that the call refuses to move, with the reason the handler gives. A null result means the thread is addressed. */
    public static function commentSkipped(string $reason): ?ApiProblemException
    {
        return match ($reason) {
            'already_addressed' => null,
            'superseded' => new ApiProblemException(new ApiProblem(409, 'comment_superseded', 'Comment superseded', 'A newer version superseded this comment. Read the review again.')),
            'already_resolved' => new ApiProblemException(new ApiProblem(409, 'comment_already_resolved', 'Comment already resolved', 'A person resolved this thread, so it cannot be addressed.')),
            'deleted' => new ApiProblemException(new ApiProblem(409, 'comment_deleted', 'Comment deleted', 'This thread is deleted. The owner must restore it first.')),
            'is_reply' => ApiProblemException::validationFailed([['field' => 'comment', 'code' => 'comment_is_reply', 'message' => 'Only the top-level comment of a thread can be addressed.']]),
            default => self::commentNotFound(),
        };
    }

    public static function commentVersionStale(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(409, 'comment_version_stale', 'Comment version stale', 'This comment belongs to an older version. Read the review for the current comment ids.'));
    }

    public static function workerRefused(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(403, 'credential_not_allowed', 'Credential not allowed', 'A bridge worker cannot write for the owner.'));
    }

    /**
     * The first refusal that is a conflict or a missing thing decides the answer. Any other refusal is a field
     * the client can correct, and they leave together as one list. A refusal that no code
     * covers stays a DomainErrors, so it is logged as a failure.
     */
    public function from(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        $fields = [];
        foreach ($errors->errors as $field => $key) {
            $sentence = ToolCallErrorMessages::sentence($key, $errors->parameters[$field] ?? []);
            if (isset(self::CONFLICTS[$key])) {
                [$status, $code, $title, $detail] = self::CONFLICTS[$key];

                return new ApiProblemException(new ApiProblem($status, $code, $title, $detail ?? $sentence), $errors);
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
