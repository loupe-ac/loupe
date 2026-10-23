<?php

namespace App\Exception;

final class DomainErrors extends \RuntimeException
{
    /**
     * @param non-empty-array<string, string>          $errors     field name => translation key
     * @param array<string, array<string, int|string>> $parameters field name => placeholders of its translation key
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $parameters = [],
    ) {
        parent::__construct('Domain errors detected.');
    }
}
