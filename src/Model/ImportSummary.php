<?php

namespace Base\Agenda\Model;

/**
 * What a sync did - or, previewed, would do: the dates it opened, the ones
 * it brought up to date, the ones its calendar cancelled, the ones gone
 * from their calendar (held, not cancelled: Change::MISSING), each as a
 * Change.
 */
final class ImportSummary
{
    /**
     * @param list<string> $errors  a source that could not be read, and why
     * @param list<Change> $changes
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $cancelled = 0,
        public int $unchanged = 0,
        public array $errors = [],
        public array $changes = [],
        public int $missing = 0,
    ) {
    }

    public function add(self $other): self
    {
        $this->created += $other->created;
        $this->updated += $other->updated;
        $this->cancelled += $other->cancelled;
        $this->unchanged += $other->unchanged;
        $this->missing += $other->missing;
        $this->errors = array_merge($this->errors, $other->errors);
        $this->changes = array_merge($this->changes, $other->changes);

        return $this;
    }

    public function record(Change $change): void
    {
        $this->changes[] = $change;
        match ($change->kind) {
            Change::CREATED => ++$this->created,
            Change::CANCELLED => ++$this->cancelled,
            Change::MISSING => ++$this->missing,
            default => ++$this->updated,
        };
    }

    public function isEmpty(): bool
    {
        return 0 === $this->created + $this->updated + $this->cancelled + $this->missing;
    }

    /** @return list<int> the dates gone from their calendar, waiting for a word */
    public function missingIds(): array
    {
        return array_values(array_filter(array_map(fn (Change $change) => Change::MISSING === $change->kind ? $change->eventId : null, $this->changes)));
    }

    /** @return array{created: int, updated: int, cancelled: int, missing: int, unchanged: int} */
    public function toArray(): array
    {
        return ['created' => $this->created, 'updated' => $this->updated, 'cancelled' => $this->cancelled, 'missing' => $this->missing, 'unchanged' => $this->unchanged];
    }
}
