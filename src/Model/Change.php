<?php

namespace Base\Agenda\Model;

/**
 * What a sync does, or would do, to one date: opens it (`created`),
 * brings it up to date (`updated`, each field as it was and as it
 * becomes), cancels it because its calendar says so (`cancelled`), or
 * finds it gone from its calendar (`missing`: held until someone says
 * whether it is cancelled or kept). Plain values, kept as JSON in the
 * sync's history.
 */
final class Change
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const CANCELLED = 'cancelled';
    public const MISSING = 'missing';

    /**
     * @param string                                   $start  the date's start on its hall's clock, "2026-11-23 20:00"
     * @param array<string, array{0: ?string, 1: ?string}> $fields field => [as it was, as it becomes]
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $start,
        public readonly ?int $eventId = null,
        public readonly array $fields = [],
    ) {
    }

    /** @return array{kind: string, title: string, start: string, eventId: ?int, fields: array<string, array{0: ?string, 1: ?string}>} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'start' => $this->start, 'eventId' => $this->eventId, 'fields' => $this->fields];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['kind'] ?? self::UPDATED),
            (string) ($data['title'] ?? ''),
            (string) ($data['start'] ?? ''),
            isset($data['eventId']) ? (int) $data['eventId'] : null,
            \is_array($data['fields'] ?? null) ? $data['fields'] : [],
        );
    }
}
