<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Service\RepositorySettingsReader;
use App\Module\Forge\Service\RepositorySettingsUnreadable;
use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositorySettings;

/** Answers the next settings in line for the `fake` forge, and counts its reads. */
final class FakeRepositorySettingsReader implements RepositorySettingsReader
{
    public const string FORGE = 'fake';

    public int $reads = 0;

    /** Runs inside each read, such as a second check that overlaps the first. */
    public ?\Closure $duringRead = null;

    /** @param list<RepositorySettings|RepositorySettingsUnreadable> $answers */
    public function __construct(
        public array $answers = [],
    ) {
    }

    public static function blocking(): RepositorySettings
    {
        return self::settings(false);
    }

    public static function clear(): RepositorySettings
    {
        return self::settings(true);
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return self::FORGE === $forge;
    }

    #[\Override]
    public function read(ForgeRepository $repository): RepositorySettings
    {
        ++$this->reads;
        $during = $this->duringRead;
        $this->duringRead = null;
        if (null !== $during) {
            $during();
        }
        $answer = array_shift($this->answers) ?? throw new \LogicException('The fake reader has no answer left.');

        return $answer instanceof RepositorySettingsUnreadable ? throw $answer : $answer;
    }

    private static function settings(bool $protected): RepositorySettings
    {
        return new RepositorySettings('main', ['squash'], null, new RepositoryRules(true, false, null, [], [], $protected, $protected), [], [], false);
    }
}
