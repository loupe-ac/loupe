<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Api;

use App\Doctrine\SearchLanguage;

/**
 * A partial change of a project. A field left out stays uninitialized and keeps
 * its value. An explicit null fails the property type, so it answers 422. The
 * handler checks every value, so a refusal reaches the client as one list.
 *
 * The generator marks a typed property with no default as required, so the
 * schema `ProjectUpdate` lives in config/packages/nelmio_api_doc.yaml.
 */
final class UpdateProjectRequest
{
    public string $name;

    public string $description;

    public string $domain;

    public SearchLanguage $searchLanguage;

    /** @var list<mixed> */
    public array $origins;
}
