<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** The `asks:` section of a template: how the inbox shapes the asks of a project. */
final readonly class TemplateAsks
{
    public const array KEYS = ['groupByCard'];

    public function __construct(
        public bool $groupByCard = true,
    ) {
    }

    /**
     * A stored copy from before the section has none, and a lenient read takes a bad value as the default.
     *
     * @param list<string> $errors
     */
    public static function parse(mixed $given, bool $lenient, array &$errors): self
    {
        if (null === $given) {
            return new self();
        }
        if (!\is_array($given) || array_is_list($given) && [] !== $given) {
            if (!$lenient) {
                $errors[] = 'asks: must be a map';
            }

            return new self();
        }
        $groupByCard = true;
        foreach ($given as $key => $value) {
            if (!\in_array($key, self::KEYS, true)) {
                if (!$lenient) {
                    $errors[] = \sprintf('asks: unknown key "%s"', $key);
                }
            } elseif (!\is_bool($value)) {
                if (!$lenient) {
                    $errors[] = \sprintf('asks.%s: must be true or false', $key);
                }
            } else {
                $groupByCard = $value;
            }
        }

        return new self($groupByCard);
    }
}
