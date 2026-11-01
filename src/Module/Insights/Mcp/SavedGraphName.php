<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Module\Insights\Entity\InsightsSavedGraph;
use Mcp\Exception\ToolCallException;

/** The name rules of the web forms, which the handlers do not hold: they would cut a long name with no message. */
final class SavedGraphName
{
    /** @throws ToolCallException when the name is longer than the column */
    public static function check(?string $name): void
    {
        if (null !== $name && mb_strlen($name) > InsightsSavedGraph::MAX_NAME_LENGTH) {
            throw new ToolCallException(\sprintf('name: A name is at most %d characters.', InsightsSavedGraph::MAX_NAME_LENGTH));
        }
    }

    /** @throws ToolCallException when the name is blank or too long */
    public static function checkNotBlank(string $name): void
    {
        if ('' === trim($name)) {
            throw new ToolCallException('name: A name must not be blank.');
        }
        self::check($name);
    }
}
