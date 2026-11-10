<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Repository;

use App\Module\Account\Entity\WaitlistBlockPattern;
use App\Module\Account\Repository\WaitlistBlockPatternRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WaitlistBlockPatternRepositoryTest extends KernelTestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        yield 'a domain' => ['*@mailinator.com', 'someone@mailinator.com', true];
        yield 'a domain ignores case' => ['*@mailinator.com', 'Someone@MAILINATOR.com', true];
        yield 'a domain does not match a longer domain' => ['*@mailinator.com', 'someone@mailinator.com.au', false];
        yield 'a suffix' => ['*.ru', 'x@y.ru', true];
        yield 'a prefix and a domain' => ['spam*@gmail.com', 'spam42@gmail.com', true];
        yield 'a prefix does not match another prefix' => ['spam*@gmail.com', 'ham42@gmail.com', false];
        yield 'the whole address must match' => ['spam@gmail.com', 'nospam@gmail.com', false];
        yield 'an underscore is literal' => ['a_b@x.com', 'acb@x.com', false];
        yield 'an underscore matches itself' => ['a_b@x.com', 'a_b@x.com', true];
        yield 'a percent sign is literal' => ['100%@x.com', '100abc@x.com', false];
        yield 'a percent sign matches itself' => ['100%@x.com', '100%@x.com', true];
        yield 'a backslash matches itself' => ['a\b@x.com', 'a\b@x.com', true];
    }

    #[DataProvider('cases')]
    public function test_matches_reads_the_pattern_as_a_glob_over_the_whole_address(string $glob, string $email, bool $expected): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $repo = self::getContainer()->get(WaitlistBlockPatternRepository::class);
        self::assertInstanceOf(WaitlistBlockPatternRepository::class, $repo);
        foreach ($repo->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->persist(new WaitlistBlockPattern($glob));
        $em->flush();

        self::assertSame($expected, $repo->matches($email));
    }

    public function test_matches_is_false_with_no_pattern(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $repo = self::getContainer()->get(WaitlistBlockPatternRepository::class);
        self::assertInstanceOf(WaitlistBlockPatternRepository::class, $repo);
        foreach ($repo->findAll() as $existing) {
            $em->remove($existing);
        }
        $em->flush();

        self::assertFalse($repo->matches('anyone@example.com'));
    }
}
