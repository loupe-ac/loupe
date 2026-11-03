<?php

declare(strict_types=1);

namespace App\Module\Project\Mcp;

use App\Exception\DomainErrors;
use App\Module\Project\Service\ProjectErrorSentences;
use Mcp\Exception\ToolCallException;

/**
 * Renders a handler's DomainErrors as the message an agent reads. An unmapped
 * key falls back to a generic message rather than leaking the key itself.
 */
final readonly class ProjectToolErrorMessages
{
    public const string UNMAPPED = ProjectErrorSentences::UNMAPPED;

    public function forAgent(DomainErrors $errors): ToolCallException
    {
        $lines = [];
        foreach ($errors->errors as $argument => $key) {
            $lines[] = \sprintf('%s: %s', $argument, ProjectErrorSentences::of($key, $errors->parameters[$argument] ?? []));
        }

        return new ToolCallException(implode("\n", $lines), previous: $errors);
    }
}
