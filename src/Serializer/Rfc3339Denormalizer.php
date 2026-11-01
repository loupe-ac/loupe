<?php

declare(strict_types=1);

namespace App\Serializer;

use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Reads a date from a request as RFC 3339 text, with or without a fraction of a second.
 *
 * It refuses a date in words and a date that is not real, such as 30 February, which
 * PHP would move to 2 March. Fractions longer than six digits keep six, as PHP does.
 */
final class Rfc3339Denormalizer implements DenormalizerInterface
{
    private const string PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D';

    #[\Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): \DateTimeImmutable
    {
        if (!\is_string($data) || 1 !== preg_match(self::PATTERN, $data)) {
            throw $this->refuse($data, $context);
        }

        try {
            $date = new \DateTimeImmutable($data);
        } catch (\DateMalformedStringException) {
            throw $this->refuse($data, $context);
        }

        if (false !== \DateTimeImmutable::getLastErrors()) {
            throw $this->refuse($data, $context);
        }

        return $date;
    }

    #[\Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return \is_string($data) && \in_array($type, [\DateTimeImmutable::class, \DateTimeInterface::class], true);
    }

    #[\Override]
    public function getSupportedTypes(?string $format): array
    {
        return [\DateTimeImmutable::class => false, \DateTimeInterface::class => false];
    }

    /** @param array<string, mixed> $context */
    private function refuse(mixed $data, array $context): NotNormalizableValueException
    {
        $path = \is_string($context['deserialization_path'] ?? null) ? $context['deserialization_path'] : null;

        return NotNormalizableValueException::createForUnexpectedDataType(
            'The data is not a valid RFC 3339 date.',
            $data,
            ['string'],
            $path,
            true,
        );
    }
}
