<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;

/**
 * A hash of the fact groups that a rule reads, so a change elsewhere leaves it as it was. The time never counts.
 *
 * Rule states stored a hash that named the groups of the old fact builder. For one release the engine accepts that
 * legacy hash as well as the hash of the providers, and writes the hash of the providers.
 */
final readonly class FactFingerprint
{
    /** @param list<EngineFact|class-string> $keys */
    public function of(Facts $facts, array $keys): string
    {
        return $this->hash($facts, $keys, false);
    }

    /**
     * The hash the old fact builder gave for the same facts, so a stored hash still compares.
     *
     * @param list<EngineFact|class-string> $keys
     */
    public function legacyOf(Facts $facts, array $keys): string
    {
        return $this->hash($facts, $keys, true);
    }

    /**
     * Whether a stored hash stands for these facts, in either form.
     *
     * @param list<EngineFact|class-string> $keys
     */
    public function sameAs(?string $stored, Facts $facts, array $keys): bool
    {
        return null !== $stored && ($stored === $this->of($facts, $keys) || $stored === $this->legacyOf($facts, $keys));
    }

    /** @param list<EngineFact|class-string> $keys */
    private function hash(Facts $facts, array $keys, bool $legacy): string
    {
        $groups = [];
        foreach ($keys as $key) {
            // The wide group already holds the state the narrow one reads, so a rule that reads both hashes as before.
            if (EngineFact::PullRequestSubject === $key && \in_array(EngineFact::PullRequest, $keys, true)) {
                continue;
            }
            if ($key instanceof EngineFact) {
                $groups[$key->value] = $this->engineGroup($facts, $key);
                continue;
            }
            // The prefix keeps a facts class apart from an engine group, and leaves the stored fingerprints as they were.
            $name = $legacy && isset($facts->legacyGroups[$key]) ? $facts->legacyGroups[$key] : 'class:'.$key;
            $groups[$name] = $facts->fingerprints[$facts->keyFor($key) ?? $key] ?? null;
        }
        ksort($groups);

        return hash('sha256', json_encode($groups, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    private function engineGroup(Facts $facts, EngineFact $key): mixed
    {
        return match ($key) {
            EngineFact::Slot => $facts->slot,
            EngineFact::ParentSlot => $facts->parentSlot,
            EngineFact::PullRequest => $facts->pullRequest?->fingerprint(),
            EngineFact::PullRequestSubject => $facts->pullRequest?->state->value,
            EngineFact::PullRequestHead => $facts->pullRequest?->headSha,
            EngineFact::PullRequestMergeGate => null === $facts->pullRequest ? null : [$facts->pullRequest->checksOnHead, $facts->pullRequest->canMerge, $facts->pullRequest->baseIsDefault],
        };
    }
}
