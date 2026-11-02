<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Api;

use App\Module\Project\Controller\Api\CreateProjectRequest;
use App\Module\Project\Service\SiteOrigins;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class CreateProjectRequestTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function names(): iterable
    {
        yield 'a given name wins' => ['  Shop ', 'git@github.com:acme/other.git', 'Shop'];
        yield 'scp remote' => ['', 'git@github.com:acme/shop.git', 'shop'];
        yield 'https remote' => ['', 'https://github.com/acme/shop.git', 'shop'];
        yield 'https remote with no suffix' => ['', 'https://github.com/acme/shop', 'shop'];
        yield 'ssh remote with a port' => ['', 'ssh://git@git.example.com:2222/team/shop.git', 'shop'];
        yield 'nested group' => ['', 'https://gitlab.com/group/sub/shop.git', 'shop'];
        yield 'blank name falls back' => ['  ', 'git@github.com:acme/shop.git', 'shop'];
        yield 'no name, no remote' => ['', '', ''];
        yield 'no name, bad remote' => ['', 'nope', ''];
    }

    #[DataProvider('names')]
    public function test_the_name(string $name, string $remote, string $expected): void
    {
        self::assertSame($expected, new CreateProjectRequest($name, '', $remote)->name());
    }

    public function test_a_blank_domain_is_none(): void
    {
        self::assertNull(new CreateProjectRequest('Shop', '  ')->domain());
        self::assertSame('https://shop.example.com', new CreateProjectRequest('Shop', ' https://shop.example.com ')->domain());
    }

    public function test_the_origins_are_normalised_without_blanks_or_duplicates(): void
    {
        $request = new CreateProjectRequest('Shop', origins: ['https://Shop.example.com/', '', ' ', 'https://shop.example.com:443']);

        self::assertSame(['https://shop.example.com'], $request->origins());
    }

    /**
     * @param list<mixed>  $origins
     * @param list<string> $paths
     */
    #[DataProvider('originLists')]
    public function test_the_origin_check(array $origins, array $paths): void
    {
        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()
            ->validate(new CreateProjectRequest('Shop', origins: $origins));

        self::assertSame($paths, array_map(static fn ($violation): string => $violation->getPropertyPath(), iterator_to_array($violations)));
    }

    /** @return iterable<string, array{list<mixed>, list<string>}> */
    public static function originLists(): iterable
    {
        yield 'valid' => [['https://shop.example.com', 'http://localhost:3000'], []];
        yield 'blank entries are skipped' => [['', ' ', 'https://shop.example.com'], []];
        yield 'a path' => [['https://shop.example.com/checkout'], ['origins[0]']];
        yield 'a number' => [[123], ['origins[0]']];
        yield 'null' => [[null], ['origins[0]']];
        yield 'an object' => [[['a' => 'b']], ['origins[0]']];
        yield 'two bad values' => [['nope', 'https://ok.example.com', 'https://*.co.uk'], ['origins[0]', 'origins[2]']];
        yield 'duplicates over the cap' => [array_fill(0, SiteOrigins::MAX + 1, 'https://shop.example.com'), []];
        yield 'distinct over the cap' => [array_map(static fn (int $n): string => 'https://site'.$n.'.example.com', range(1, SiteOrigins::MAX + 1)), ['origins']];
        yield 'a bad value hides the count' => [[...array_map(static fn (int $n): string => 'https://site'.$n.'.example.com', range(1, SiteOrigins::MAX + 1)), 'nope'], ['origins['.(SiteOrigins::MAX + 1).']']];
    }
}
