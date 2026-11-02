<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\Problem\ApiProblemException;
use Symfony\Component\HttpFoundation\Request;

/** Reads the `versionNumber` that a delete route takes in the query string, because a delete has no body. */
final readonly class VersionNumberQuery
{
    /** @throws ApiProblemException `validation_failed` when the number is missing or is not a positive integer */
    public static function fromRequest(Request $request): int
    {
        $value = $request->query->get('versionNumber');
        $number = \is_string($value) ? filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        if (false === $number) {
            throw ApiProblemException::validationFailed([['field' => 'versionNumber', 'code' => 'version_number_invalid', 'message' => 'versionNumber must be a positive integer, the version you showed the owner.']]);
        }

        return $number;
    }
}
