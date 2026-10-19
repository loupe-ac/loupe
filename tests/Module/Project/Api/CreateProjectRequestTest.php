<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Api;

use App\Module\Project\Controller\Api\CreateProjectRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateProjectRequestTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, string}> */
    public static function names(): iterable
    {
        yield 'a given name wins' => ['  Shop ', 'git@github.com:acme/other.git', 'Shop'];
        yield 'scp remote' => [null, 'git@github.com:acme/shop.git', 'shop'];
        yield 'https remote' => [null, 'https://github.com/acme/shop.git', 'shop'];
        yield 'https remote with no suffix' => [null, 'https://github.com/acme/shop', 'shop'];
        yield 'ssh remote with a port' => [null, 'ssh://git@git.example.com:2222/team/shop.git', 'shop'];
        yield 'nested group' => [null, 'https://gitlab.com/group/sub/shop.git', 'shop'];
        yield 'blank name falls back' => ['  ', 'git@github.com:acme/shop.git', 'shop'];
        yield 'no name, no remote' => [null, null, ''];
        yield 'no name, bad remote' => [null, 'nope', ''];
    }

    #[DataProvider('names')]
    public function test_the_name(?string $name, ?string $remote, string $expected): void
    {
        self::assertSame($expected, new CreateProjectRequest($name, null, $remote)->name());
    }

    public function test_a_blank_website_is_none(): void
    {
        self::assertNull(new CreateProjectRequest('Shop', '  ')->website());
        self::assertSame('https://shop.example.com', new CreateProjectRequest('Shop', ' https://shop.example.com ')->website());
    }
}
