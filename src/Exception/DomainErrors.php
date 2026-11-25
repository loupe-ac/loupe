<?php

namespace App\Exception;

final class DomainErrors extends \RuntimeException
{
    /**
     * @param non-empty-array<string, string>          $errors     field name => translation key
     * @param array<string, array<string, int|string>> $parameters field name => placeholders of its translation key
     * @param bool                                     $fromGuard  a plugged-in guard chose the keys, so a surface that reads this mark translates them and keeps no list of them
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $parameters = [],
        public readonly bool $fromGuard = false,
    ) {
        parent::__construct('Domain errors detected.');
    }
}
