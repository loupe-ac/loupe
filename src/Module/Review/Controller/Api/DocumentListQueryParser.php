<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\Pagination\CreatedSort;
use App\Api\Problem\ApiProblemException;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use App\Module\Review\View\DocumentPageOrder;
use App\Module\Review\View\DocumentPageQuery;
use Symfony\Component\HttpFoundation\Request;

/** Reads the filters and the sort of the document list from the query string. */
final readonly class DocumentListQueryParser
{
    /** @throws ApiProblemException `validation_failed` for a filter or a sort the list does not accept */
    public static function fromRequest(Request $request): DocumentPageQuery
    {
        $query = $request->query->all();
        $errors = [];

        $text = static function (string $name) use ($query, &$errors): ?string {
            $value = $query[$name] ?? null;
            if (null === $value) {
                return null;
            }
            if (!\is_string($value)) {
                $errors[] = ['field' => $name, 'code' => $name.'_invalid', 'message' => sprintf('The %s filter takes one text value.', $name)];

                return null;
            }

            return $value;
        };

        $search = $text('q');
        $search = null === $search || '' === trim($search) ? null : trim($search);

        $statusText = $text('status');
        $status = null;
        if (null !== $statusText && '' !== trim($statusText)) {
            $status = DocumentStatus::tryFrom($statusText);
            if (null === $status) {
                $errors[] = ['field' => 'status', 'code' => 'status_invalid', 'message' => sprintf('Unknown status "%s". Use one of: %s.', $statusText, implode(', ', DocumentStatus::values()))];
            }
        }

        $tagText = $text('tag');
        $tag = null === $tagText ? '' : Tag::normalizeName($tagText);
        $seriesText = $text('series');
        $series = null === $seriesText ? '' : Series::normalizeName($seriesText);

        $includeArchived = false;
        if (\array_key_exists('includeArchived', $query)) {
            $flag = \is_string($query['includeArchived']) ? filter_var($query['includeArchived'], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) : null;
            if (null === $flag) {
                $errors[] = ['field' => 'includeArchived', 'code' => 'include_archived_invalid', 'message' => 'includeArchived must be true or false.'];
            }
            $includeArchived = true === $flag;
        }

        if ([] !== $errors) {
            throw ApiProblemException::validationFailed($errors);
        }

        $seriesName = '' === $series ? null : $series;
        $order = match (true) {
            $request->query->has('sort') => CreatedSort::fromRequest($request)->newestFirst() ? DocumentPageOrder::Newest : DocumentPageOrder::Oldest,
            null !== $seriesName => DocumentPageOrder::Series,
            null !== $search => DocumentPageOrder::Relevance,
            default => DocumentPageOrder::Oldest,
        };

        return new DocumentPageQuery(
            order: $order,
            includeArchived: $includeArchived,
            search: $search,
            status: $status,
            tagName: '' === $tag ? null : $tag,
            seriesName: $seriesName,
        );
    }
}
