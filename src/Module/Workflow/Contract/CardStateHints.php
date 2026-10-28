<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** What the workflow knows about the state of cards that Board shows on a tile. The engine implements it. */
interface CardStateHints
{
    /** Null when the project has no template. */
    public function stageDocumentTags(Uuid $projectId): ?StageDocumentTags;

    /**
     * The cards that a move rule holds for an open blocker alone, each with its open blocker of the lowest number.
     *
     * @param list<string> $cardIds RFC 4122 ids
     *
     * @return list<BlockerHold>
     */
    public function blockerHolds(array $cardIds): array;

    /**
     * Since when a rule of each card waits because its project holds its new work. A card with no such rule is left out.
     *
     * @param list<string> $cardIds RFC 4122 ids
     *
     * @return array<string, \DateTimeImmutable> card id => the earliest stamp
     */
    public function workHoldsSince(array $cardIds): array;

    /**
     * The columns whose slot has a rule of its own that reads a pull request.
     *
     * @return list<string> RFC 4122 column ids; empty when the project has no template
     */
    public function pullRequestColumnIds(Uuid $projectId): array;

    /**
     * The branch of each epic with these card numbers. It reads the template once, whatever the count.
     *
     * @param list<int> $numbers
     *
     * @return array<int, string> card number => its branch; empty when the project has no template or its template has no epic branches
     */
    public function epicBranchesOf(Uuid $projectId, array $numbers): array;
}
