<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Service\ForgeConnectionReader;
use App\Module\Forge\Service\ForgeConnectionReaders;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class ForgeConnectionReadersTest extends TestCase
{
    public function test_the_first_connected_reader_that_accepts_the_path_answers(): void
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'p');
        $notConnected = $this->reader('one', accepts: true, connected: false);
        $wrongPath = $this->reader('two', accepts: false, connected: true);
        $first = $this->reader('three', accepts: true, connected: true);
        $second = $this->reader('four', accepts: true, connected: true);

        $readers = new ForgeConnectionReaders([$notConnected, $wrongPath, $first, $second]);

        self::assertSame($first, $readers->for($project, 'group/repo'));
    }

    public function test_no_reader_answers_when_none_is_connected_and_accepts(): void
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'p');
        $readers = new ForgeConnectionReaders([
            $this->reader('one', accepts: true, connected: false),
            $this->reader('two', accepts: false, connected: true),
        ]);

        self::assertNull($readers->for($project, 'group/repo'));
        self::assertNull(new ForgeConnectionReaders([])->for($project, 'group/repo'));
    }

    public function test_the_reader_of_a_forge_answers_with_no_connection_check(): void
    {
        $other = $this->reader('one', accepts: true, connected: false);
        $mine = $this->reader('two', accepts: true, connected: false);
        $readers = new ForgeConnectionReaders([$other, $mine]);

        self::assertSame($mine, $readers->forForge('two', 'group/repo'));
        self::assertNull($readers->forForge('three', 'group/repo'));
        self::assertNull(new ForgeConnectionReaders([$this->reader('two', accepts: false, connected: true)])->forForge('two', 'group/repo'));
    }

    private function reader(string $forge, bool $accepts, bool $connected): ForgeConnectionReader
    {
        return new readonly class($forge, $accepts, $connected) implements ForgeConnectionReader {
            public function __construct(
                private string $forge,
                private bool $accepts,
                private bool $connected,
            ) {
            }

            #[\Override]
            public function supports(string $forge): bool
            {
                return $forge === $this->forge;
            }

            #[\Override]
            public function acceptsPath(string $path): bool
            {
                return $this->accepts;
            }

            #[\Override]
            public function isConnected(Project $project): bool
            {
                return $this->connected;
            }

            #[\Override]
            public function pullRequestUrl(string $path, int $number): string
            {
                return 'https://'.$this->forge.'.example/'.$path.'/'.$number;
            }
        };
    }
}
