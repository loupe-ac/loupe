<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** One marker of a card as the engine stores it, so a read needs no template. */
final readonly class StoredMarker
{
    /**
     * @param string                    $label   a translation key
     * @param ?string                   $remedy  a translation key
     * @param array<string, string|int> $params  the label parameters, such as `%number%`
     * @param ?MarkerLink               $link    the page to link to under the remedy
     * @param ?MarkerSubject            $subject the thing the entry is about, set on the entries of the inbox set only
     */
    public function __construct(
        public string $key,
        public MarkerKind $kind,
        public string $label,
        public ?string $remedy,
        public array $params,
        public \DateTimeImmutable $since,
        public ?MarkerLink $link = null,
        public ?MarkerSubject $subject = null,
    ) {
    }

    public function withSince(\DateTimeImmutable $since): self
    {
        return new self($this->key, $this->kind, $this->label, $this->remedy, $this->params, $since, $this->link, $this->subject);
    }

    public function withoutSubject(): self
    {
        return new self($this->key, $this->kind, $this->label, $this->remedy, $this->params, $this->since, $this->link);
    }

    public function withSubject(MarkerSubject $subject, \DateTimeImmutable $since): self
    {
        return new self($this->key, $this->kind, $this->label, $this->remedy, $this->params, $since, $this->link, $subject);
    }
}
