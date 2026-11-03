<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Api\Problem\ApiProblemException;
use Symfony\Component\HttpFoundation\Request;

/** The optional `versionNumber` query value of an artifact read. */
final readonly class VersionNumber
{
    /** @throws ApiProblemException `validation_failed` for a value that is no whole number from 1 */
    public static function fromRequest(Request $request): ?int
    {
        $value = $request->query->all()['versionNumber'] ?? null;
        if (null === $value) {
            return null;
        }

        $number = \is_string($value) ? filter_var($value, \FILTER_VALIDATE_INT) : false;
        if (false === $number || $number < 1) {
            throw ApiProblemException::validationFailed([['field' => 'versionNumber', 'code' => 'version_number_invalid', 'message' => 'The versionNumber must be a whole number from 1.']]);
        }

        return $number;
    }
}
