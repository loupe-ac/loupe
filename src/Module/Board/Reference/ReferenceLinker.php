<?php

declare(strict_types=1);

namespace App\Module\Board\Reference;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\ForgeConnectionReader;
use App\Module\Forge\Service\ForgeConnectionReaders;
use App\Module\Project\Entity\Project;
use App\Module\Review\Service\ReferenceTokens;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns the card and pull request references of one rendered region into links.
 *
 * Only elements that carry `data-references` are read. The pass adds tags and
 * attributes and never text, so the shown text of a document stays equal to its
 * stored text and comment anchors keep their place.
 */
final readonly class ReferenceLinker
{
    private const string NUMBER = '[1-9][0-9]{0,8}';
    private const array SKIPPED = ['code', 'pre', 'a', 'label', 'button', 'textarea'];
    private const array VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    public function __construct(
        private CardRepository $cards,
        private ForgeRepositoryRepository $forgeRepositories,
        private ForgePullRequestRepository $forgePullRequests,
        private ForgeConnectionReaders $readers,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private ReferenceTokens $tokens,
    ) {
    }

    public function link(string $html, Project $project): string
    {
        if (!str_contains($html, 'data-references')) {
            return $html;
        }

        $cardNumbers = [];
        $prReferences = [];
        $proseNumbers = [];
        $this->rewrite($html, static function (string $kind, string $path, int $number, string $text) use (&$cardNumbers, &$prReferences, &$proseNumbers): string {
            match ($kind) {
                'card' => $cardNumbers[] = $number,
                'pr' => $prReferences[] = [$path, $number],
                default => $proseNumbers[] = $number,
            };

            return $text;
        });
        if ([] === $cardNumbers && [] === $prReferences && [] === $proseNumbers) {
            return $html;
        }

        $cards = $this->cards->findByProjectAndNumbers($project, $cardNumbers);
        $watched = [] === $prReferences && [] === $proseNumbers ? [] : $this->forgeRepositories->findByProject($project);
        $proseRepository = [] !== $proseNumbers && 1 === \count($watched) ? $watched[0] : null;

        $keys = [];
        foreach ($prReferences as [$path, $number]) {
            $repository = $this->watched($watched, $path);
            if (null !== $repository) {
                $keys[] = ['forge' => $repository->forge, 'repository' => $repository->path, 'number' => $number];
            }
        }
        if (null !== $proseRepository) {
            foreach ($proseNumbers as $number) {
                $keys[] = ['forge' => $proseRepository->forge, 'repository' => $proseRepository->path, 'number' => $number];
            }
        }

        $rows = [];
        foreach ([] === $keys ? [] : $this->forgePullRequests->findByKeys($project->requireId(), $keys) as $row) {
            $rows[self::rowKey($row->forge, $row->repository, $row->number)] = $row;
        }

        $connected = null;

        return $this->rewrite($html, function (string $kind, string $path, int $number, string $text) use ($project, $cards, $watched, $proseRepository, $rows, &$connected): string {
            if ('card' === $kind) {
                $card = $cards[$number] ?? null;

                return null === $card ? $text : $this->cardLink($card, $text);
            }

            $repository = 'pr' === $kind ? $this->watched($watched, $path) : $proseRepository;
            if (null === $repository) {
                if ('pr' !== $kind) {
                    return $text;
                }
                $connected ??= $this->readers->connected($project, array_map(static fn (ForgeRepository $repository): string => $repository->forge, $watched));
                $reader = array_find($connected, static fn (ForgeConnectionReader $reader): bool => $reader->acceptsPath($path));

                return null === $reader ? $text : $this->pullRequestLink($reader->pullRequestUrl($path, $number), null, $text);
            }

            $url = $this->readers->forForge($repository->forge, $repository->path)?->pullRequestUrl($repository->path, $number);
            if (null === $url) {
                return $text;
            }

            return $this->pullRequestLink($url, $rows[self::rowKey($repository->forge, $repository->path, $number)] ?? null, $text);
        });
    }

    /**
     * Walks the text of every `data-references` region. The callback receives the
     * kind (`card`, `pr` or `prose-pr`), the repository path of a `pr` token, the
     * number and the shown text, and returns the HTML that takes the text's place.
     *
     * @param \Closure(string, string, int, string): string $link
     */
    private function rewrite(string $html, \Closure $link): string
    {
        $tokens = preg_split('~(<[^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE)
            ?: throw new \RuntimeException('Reference linking failed: '.preg_last_error_msg().'.');

        $region = null;
        $skipDepth = 0;
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (0 === $i % 2) {
                if (null !== $region && 0 === $skipDepth) {
                    $tokens[$i] = $this->prose($token, $link);
                }

                continue;
            }

            if (1 !== preg_match('~^<(/?)([a-zA-Z][a-zA-Z0-9-]*)~', $token, $tag)) {
                continue;
            }
            $name = strtolower($tag[2]);
            $closing = '/' === $tag[1];
            if (\in_array($name, self::VOID, true)) {
                continue;
            }

            if (null === $region) {
                if (!$closing && 1 === preg_match('~\sdata-references(?=[\s=>/])~', $token)) {
                    $region = [$name, 1];
                }

                continue;
            }

            if ($name === $region[0]) {
                $region[1] += $closing ? -1 : 1;
                if (0 === $region[1]) {
                    $region = null;
                    $skipDepth = 0;

                    continue;
                }
            }
            if (\in_array($name, self::SKIPPED, true)) {
                $skipDepth = max(0, $skipDepth + ($closing ? -1 : 1));

                continue;
            }

            if (0 === $skipDepth && !$closing && 'span' === $name
                && 1 === preg_match('~^<span data-ref-(card|pr)="([^"]*)">$~', $token, $mark)
                && '</span>' === ($tokens[$i + 2] ?? null)
            ) {
                $replacement = $this->mark($mark[1], $mark[2], $tokens[$i + 1], $link);
                if (null !== $replacement) {
                    $tokens[$i] = $replacement;
                    $tokens[$i + 1] = '';
                    $tokens[$i + 2] = '';
                    if ('span' === $region[0]) {
                        --$region[1];
                    }
                    $i += 2;
                }
            }
        }

        return implode('', $tokens);
    }

    /** @param \Closure(string, string, int, string): string $link */
    private function mark(string $kind, string $value, string $text, \Closure $link): ?string
    {
        if ('card' === $kind) {
            return 1 === preg_match('~^'.self::NUMBER.'$~', $value) ? $this->replaced($link('card', '', (int) $value, $text), $text) : null;
        }
        if (1 !== preg_match('~^(.+)#('.self::NUMBER.')$~', $value, $match)) {
            return null;
        }

        return $this->replaced($link('pr', $match[1], (int) $match[2], $text), $text);
    }

    /** A callback that returns the text unchanged leaves the marked span as written. */
    private function replaced(string $linked, string $text): ?string
    {
        return $linked === $text ? null : $linked;
    }

    /** @param \Closure(string, string, int, string): string $link */
    private function prose(string $text, \Closure $link): string
    {
        $pattern = '~(?<![\p{L}\p{N}])(?<word>cards?|prs?|pull\s+requests?)(?<gap>\s+)(?<first>\#?(?<digits>'.self::NUMBER.'))'
            .'(?<rest>(?:\s*(?:,\s*(?:(?:and|or)\s+)?|(?:and|or)\s+)\#?'.self::NUMBER.')*)(?![\p{L}\p{N}])~iu';

        return preg_replace_callback($pattern, function (array $match) use ($link): string {
            $kind = 'c' === strtolower($match['word'][0]) ? 'card' : 'prose-pr';
            $out = $link($kind, '', (int) $match['digits'], $match['word'].$match['gap'].$match['first']);
            $rest = preg_replace_callback(
                '~\#?('.self::NUMBER.')(?![\p{L}\p{N}])~u',
                static fn (array $number): string => $link($kind, '', (int) $number[1], $number[0]),
                $match['rest'],
            ) ?? $match['rest'];

            return $out.$rest;
        }, $text) ?? $text;
    }

    /** Stored pull request rows hold the repository path in lower case. */
    private static function rowKey(string $forge, string $repository, int $number): string
    {
        return $forge.'|'.mb_strtolower($repository).'|'.$number;
    }

    /** @param list<ForgeRepository> $watched */
    private function watched(array $watched, string $path): ?ForgeRepository
    {
        return array_find($watched, static fn (ForgeRepository $repository): bool => mb_strtolower($repository->path) === mb_strtolower($path));
    }

    private function cardLink(Card $card, string $text): string
    {
        $state = StoredCardMarkers::of($card)->state();
        $line = $this->translator->trans($card->column->label);
        if (null !== $state) {
            $line .= ' · '.$this->translator->trans($state->label, $state->params);
        }

        return \sprintf(
            '<a href="%s" data-card-drawer-opener data-drawer-title="#%d"%s>%s</a>',
            $this->escape($this->urls->generate('app_board_card', ['projectId' => $card->project->requireId(), 'cardId' => $card->id])),
            $card->number,
            $this->tooltip($card->title, $line),
            $text,
        );
    }

    private function pullRequestLink(string $url, ?ForgePullRequest $row, string $text): string
    {
        $tooltip = '';
        if (null !== $row) {
            $line = match ($row->state) {
                PullRequestState::Open => $this->translator->trans('board.card.link.state.open')
                    .($row->draft ? ' · '.$this->translator->trans('board.card.link.state.draft') : ''),
                PullRequestState::Merged => $this->translator->trans('board.card.link.state.merged'),
                PullRequestState::Closed => $this->translator->trans('board.card.link.state.closed'),
            };
            $tooltip = $this->tooltip($row->title, $line);
        }

        return \sprintf('<a href="%s" target="_blank" rel="noopener noreferrer"%s>%s</a>', $this->escape($url), $tooltip, $text);
    }

    private function tooltip(?string $title, string $line): string
    {
        $out = '';
        if (null !== $title && '' !== trim($title)) {
            $out .= ' data-ref-tooltip-title="'.$this->escape($this->tokens->plain($title)).'"';
        }

        return $out.' data-ref-tooltip-line="'.$this->escape($line).'"';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
