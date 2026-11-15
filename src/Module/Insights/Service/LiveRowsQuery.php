<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Uid\Uuid;

/** What a rows read of a live list names: the ids of the shown rows and the time the page loaded. */
final readonly class LiveRowsQuery
{
    public const int MAX_IDS = 100;

    /** @param list<Uuid> $ids */
    public function __construct(
        public array $ids,
        public ?\DateTimeImmutable $since,
    ) {
    }

    /**
     * @param InputBag<string> $query
     *
     * @throws BadRequestException when an id is not an id, there are too many ids, or the time does not parse
     */
    public static function fromQuery(InputBag $query): self
    {
        $text = trim($query->getString('ids'));
        $parts = '' === $text ? [] : array_values(array_unique(explode(',', $text)));
        if (\count($parts) > self::MAX_IDS) {
            throw new BadRequestException('Too many ids.');
        }
        $ids = [];
        foreach ($parts as $part) {
            if (!Uuid::isValid($part)) {
                throw new BadRequestException('An id is not valid.');
            }
            $ids[] = Uuid::fromString($part);
        }

        $sinceText = trim($query->getString('since'));
        $since = null;
        if ('' !== $sinceText) {
            $parsed = \DateTimeImmutable::createFromFormat(\DATE_RFC3339_EXTENDED, $sinceText)
                ?: \DateTimeImmutable::createFromFormat(\DATE_ATOM, $sinceText);
            if (false === $parsed) {
                throw new BadRequestException('The time is not valid.');
            }
            $since = $parsed->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }

        return new self($ids, $since);
    }
}
