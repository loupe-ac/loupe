<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\StoredMarker;
use App\Module\Workflow\Engine\Evaluation;
use App\Module\Workflow\Engine\MarkerPass;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateParser;
use Symfony\Component\Uid\Uuid;

/** Runs the marker pass on built facts, with a store that only remembers what it was given. */
trait MarkerScenario
{
    private ?\DateTimeImmutable $writtenDueAt = null;

    private ?Uuid $markerCardId = null;

    /** @var list<StoredMarker>|null null until the pass writes */
    private ?array $written = null;

    /** @var list<StoredMarker>|null null until the pass writes */
    private ?array $writtenInbox = null;

    /**
     * @param list<array<string, mixed>> $markers
     * @param list<string>               $inbox   the keys of the markers that store one inbox entry per thing
     * @param array<string, mixed>       $facts   the named facts of the template
     */
    private function markerTemplate(array $markers, array $inbox = [], array $facts = []): Template
    {
        $parser = self::getContainer()->get(TemplateParser::class);
        self::assertInstanceOf(TemplateParser::class, $parser);

        $parsed = $parser->parse([
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [['key' => 'one', 'label' => 'one'], ['key' => 'two', 'label' => 'two']],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'rules' => [],
            ...([] === $facts ? [] : ['facts' => $facts]),
            'markers' => $markers,
        ]);

        // The parser refuses a listed marker with the card subject, and these scenarios list such markers on purpose.
        return new Template(
            $parsed->key,
            $parsed->version,
            $parsed->slots,
            $parsed->rules,
            $parsed->manualMoves,
            $parsed->backoffMinutes,
            $parsed->types,
            $parsed->defaultType,
            markers: $parsed->markers,
            inbox: $inbox,
        );
    }

    /**
     * Runs the pass and answers the stored keys, in the stored order.
     *
     * @param list<StoredMarker> $stored what the board set of the card held before the pass
     *
     * @return list<string>
     */
    private function passKeys(Template $template, Facts $facts, array $stored = []): array
    {
        $this->pass($template, $facts, $stored);

        return array_map(static fn (StoredMarker $marker): string => $marker->key, $this->written ?? []);
    }

    /**
     * @param list<StoredMarker>  $stored      the board set before the pass
     * @param ?list<StoredMarker> $storedInbox the inbox set before the pass, null for a card that this version has not read
     */
    private function pass(Template $template, Facts $facts, array $stored = [], ?array $storedInbox = null): void
    {
        $this->written = null;
        $this->writtenInbox = null;
        $this->writtenDueAt = null;
        $store = new RecordingMarkerStore($stored, $storedInbox);
        $card = new CardSnapshot($this->markerCardId ??= Uuid::v7(), Uuid::v7(), 1, 'feature', new ColumnRef(Uuid::v7(), false, false), null, null, null);
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed'), 'marker-pass');

        new MarkerPass(new RuleSubject(), $store)->run(new Evaluation($card, $project, $template, $facts, [], $facts->now));
        $this->written = $store->written;
        $this->writtenInbox = $store->writtenInbox;
        $this->writtenDueAt = $store->dueAt;
    }

    private function written(string $key): StoredMarker
    {
        foreach ($this->written ?? [] as $marker) {
            if ($marker->key === $key) {
                return $marker;
            }
        }

        throw new \LogicException(\sprintf('The pass stored no marker "%s".', $key));
    }
}
