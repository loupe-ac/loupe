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
    public function test_the_connected_readers_keep_their_order_and_a_watched_forge_skips_the_check(): void
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'p');
        $notConnected = $this->reader('one', accepts: true, connected: false);
        $watched = $this->reader('two', accepts: true, connected: false);
        $first = $this->reader('three', accepts: false, connected: true);
        $second = $this->reader('four', accepts: true, connected: true);

        $readers = new ForgeConnectionReaders([$notConnected, $watched, $first, $second]);

        self::assertSame([$watched, $first, $second], $readers->connected($project, ['two']));
        self::assertSame([], new ForgeConnectionReaders([])->connected($project, ['two']));
    }

    public function test_each_reader_is_asked_once_and_not_at_all_for_a_watched_forge(): void
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'p');
        $asked = $this->reader('one', accepts: true, connected: true);
        $watched = $this->reader('two', accepts: true, connected: true);

        new ForgeConnectionReaders([$asked, $watched])->connected($project, ['two', 'two']);

        self::assertSame(1, $asked->checks);
        self::assertSame(0, $watched->checks);
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

    /** @return ForgeConnectionReader&object{checks: int} */
    private function reader(string $forge, bool $accepts, bool $connected): ForgeConnectionReader
    {
        return new class($forge, $accepts, $connected) implements ForgeConnectionReader {
            public int $checks = 0;

            public function __construct(
                private readonly string $forge,
                private readonly bool $accepts,
                private readonly bool $connected,
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
                ++$this->checks;

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
