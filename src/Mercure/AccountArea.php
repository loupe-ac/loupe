<?php

declare(strict_types=1);

namespace App\Mercure;

/** The part of an account that an account.changed signal names. A page listens for the areas it shows. */
final class AccountArea
{
    public const string BILLING = 'billing';
    public const string EXPORTS = 'exports';
    public const string API_KEYS = 'api_keys';
    public const string APPS = 'apps';
    public const string PROFILE = 'profile';

    private function __construct()
    {
    }
}
