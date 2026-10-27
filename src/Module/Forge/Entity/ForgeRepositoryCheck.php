<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\SuggestionMark;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The stored result of the checks of one repository. The items and the settings
 * stay those of the last certain read, and a failed read changes only
 * attemptedAt and failure.
 */
#[ORM\Entity(repositoryClass: ForgeRepositoryCheckRepository::class)]
#[ORM\Table(name: 'forge_repository_checks')]
class ForgeRepositoryCheck
{
    public const string STATE_FITS = 'fits';
    public const string STATE_GAP = 'gap';

    /** The key of the warning row that a branch with classic protection gets. No template suggests it. */
    public const string KEY_CLASSIC_PROTECTION = 'classic-protection';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** The last certain read. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $attemptedAt = null;

    /** The reason code of the last failed read, null after a certain read. */
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $failure = null;

    /**
     * One entry for each line of the check. The gaps are reason codes, empty
     * when the state is fits.
     *
     * @var list<array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}>
     */
    #[ORM\Column(type: Types::JSON)]
    public array $items = [];

    /** @var array<string, mixed>|null the RepositorySettings of the last certain read */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $settings = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $nextReadTicket = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $acceptedReadTicket = 0;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: ForgeRepository::class)]
        public readonly ForgeRepository $repository,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,
    ) {
    }

    /**
     * @param list<array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}> $items
     */
    public static function countBlockingGaps(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (SuggestionMark::Blocking->value === $item['mark'] && self::STATE_GAP === $item['state']) {
                $count += \count($item['gaps']);
            }
        }

        return $count;
    }
}
