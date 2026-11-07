<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Reads the `project` id and the `workflow:` map out of the text of `.loupe.yaml`. */
final class WorkflowFileSettings
{
    private function __construct()
    {
    }

    /**
     * Parses with no flags, so a tag or an object in the file is a parse error.
     *
     * @return array<string, mixed>|null the `workflow:` map, an empty array when the file holds none, or null when the file is not usable for this project
     */
    public static function forProject(string $content, Uuid $projectId): ?array
    {
        try {
            $data = Yaml::parse($content);
        } catch (ParseException) {
            return null;
        }

        $project = \is_array($data) ? ($data['project'] ?? null) : null;
        if (!\is_string($project) || !Uuid::isValid($project) || !Uuid::fromString($project)->equals($projectId)) {
            return null;
        }

        $workflow = $data['workflow'] ?? null;
        if (!\is_array($workflow) || array_is_list($workflow)) {
            return [];
        }

        return $workflow;
    }
}
