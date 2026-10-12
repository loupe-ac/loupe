<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Security;

use App\Module\Account\Entity\User;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Security\ArtifactVoter;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class ArtifactVoterTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function attributes(): iterable
    {
        yield 'view' => [ArtifactVoter::VIEW];
        yield 'manage' => [ArtifactVoter::MANAGE];
    }

    #[DataProvider('attributes')]
    public function test_the_project_owner_is_granted(string $attribute): void
    {
        [$artifact, $owner] = $this->artifact();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($owner, $attribute, $artifact));
    }

    #[DataProvider('attributes')]
    public function test_another_user_is_denied(string $attribute): void
    {
        [$artifact] = $this->artifact();
        $stranger = new User(fullName: 'Sam Stranger', email: 'stranger@example.com', password: 'x');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($stranger, $attribute, $artifact));
    }

    public function test_it_abstains_on_an_unknown_attribute_and_on_another_subject(): void
    {
        [$artifact, $owner] = $this->artifact();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($owner, 'artifact.delete', $artifact));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($owner, ArtifactVoter::VIEW, $artifact->project));
    }

    /** @return array{Artifact, User} */
    private function artifact(): array
    {
        $owner = new User(fullName: 'Riley Chen', email: 'owner@example.com', password: 'x');
        $project = new Project($owner, 'Artifacts');

        return [new Artifact($project, 'Prototype'), $owner];
    }

    private function vote(User $user, string $attribute, mixed $subject): int
    {
        return new ArtifactVoter()->vote(new UsernamePasswordToken($user, 'main', []), $subject, [$attribute]);
    }
}
