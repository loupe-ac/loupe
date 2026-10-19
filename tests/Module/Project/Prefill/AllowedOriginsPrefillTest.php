<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Prefill;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Form\UpdateProjectAllowedOriginsRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllowedOriginsPrefillTest extends TestCase
{
    /** @return iterable<string, array{?string, list<string>, ?string}> */
    public static function projects(): iterable
    {
        yield 'a bare domain becomes an https origin' => ['acme.example', [], 'https://acme.example'];
        yield 'a full origin stays as it is' => ['https://acme.example', [], 'https://acme.example'];
        yield 'a path is not an origin' => ['acme.example/blog', [], null];
        yield 'no domain leaves the field empty' => [null, [], ''];
        yield 'a saved origin wins over the domain' => ['acme.example', ['https://preview.example'], 'https://preview.example'];
    }

    /**
     * @param list<string> $origins
     */
    #[DataProvider('projects')]
    public function test_the_field_starts_with_the_website_only_when_no_origin_is_saved(?string $domain, array $origins, ?string $expected): void
    {
        $project = new Project(new User('Owner', 'owner@example.com'), 'Acme', domain: $domain);
        $project->allowedOrigins = $origins;

        self::assertSame($expected, UpdateProjectAllowedOriginsRequest::fromProject($project)->origins);
    }
}
