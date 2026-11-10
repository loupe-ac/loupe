<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

use App\Module\Account\Repository\WaitlistBlockPatternRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A glob over the full email address: `*` stands for any text, and the match
 * ignores case. A waitlist entry whose address matches stays in the table and
 * is hidden from the admin page, its counts and its invites.
 */
#[ORM\Entity(repositoryClass: WaitlistBlockPatternRepository::class)]
#[ORM\Table(name: 'waitlist_block_patterns')]
class WaitlistBlockPattern
{
    public const int MAX_LENGTH = 180;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** The LIKE form of the pattern, which the SQL matches with `\` as the escape character. */
    #[ORM\Column(length: 400)]
    public private(set) string $likePattern;

    public function __construct(
        #[ORM\Column(length: self::MAX_LENGTH, unique: true)]
        public string $pattern {
            set(string $pattern) {
                $pattern = strtolower(trim($pattern));
                if ('' === trim($pattern, "* \t\n\r\0\x0B")) {
                    throw new \InvalidArgumentException('A block pattern needs at least one character other than "*".');
                }
                $this->pattern = $pattern;
                $this->likePattern = self::computeLikePattern($pattern);
            }
        },

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public static function computeLikePattern(string $pattern): string
    {
        return strtr(strtolower(trim($pattern)), ['\\' => '\\\\', '%' => '\%', '_' => '\_', '*' => '%']);
    }
}
