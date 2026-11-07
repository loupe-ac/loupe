<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Entity;

use App\Module\Account\Entity\User;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicLinkKind;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class PublicLinkTest extends TestCase
{
    public function test_an_experiment_link_starts_on_with_a_fresh_code(): void
    {
        $link = PublicLink::forExperiment($this->project(), 'implement', 'A note');

        self::assertSame(PublicLinkKind::Experiment, $link->kind);
        self::assertSame('implement', $link->experiment);
        self::assertNull($link->savedGraph);
        self::assertTrue($link->enabled);
        self::assertSame('A note', $link->note);
        self::assertMatchesRegularExpression('/^[a-z2-7]{12}$/D', $link->code);
    }

    public function test_a_graph_link_must_not_name_an_experiment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PublicLink($this->project(), PublicLinkKind::Graph, 'abcdefghijkl', experiment: 'implement');
    }

    public function test_an_experiment_link_needs_an_experiment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PublicLink($this->project(), PublicLinkKind::Experiment, 'abcdefghijkl');
    }

    private function project(): Project
    {
        return new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'Demo');
    }
}
