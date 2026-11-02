<?php

declare(strict_types=1);

namespace App\DevSeed;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsDecorator('doctrine.uuid_generator')]
#[When('dev')]
#[When('test')]
final class SeededUuidGenerator extends AbstractIdGenerator
{
    public function __construct(
        #[AutowireDecorated]
        private readonly AbstractIdGenerator $inner,
        private readonly DevSeedIds $ids,
    ) {
    }

    #[\Override]
    public function generateId(EntityManagerInterface $em, ?object $entity): mixed
    {
        return $this->ids->take($entity) ?? $this->inner->generateId($em, $entity);
    }
}
