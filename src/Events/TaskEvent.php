<?php

namespace Kraftausdruck\Events;

/**
 * Marker + serialization contract for task lifecycle events.
 *
 * Events are serializable value objects (scalars/IDs only — no live objects,
 * no closures) so they can cross the process boundary as a JSONL wire record
 * and be rebuilt on the receiver. The wire format is a versioned contract.
 */
interface TaskEvent
{
    /**
     * Encode to a scalar-only array for the wire.
     *
     * @return array<string, scalar|null>
     */
    public function toArray(): array;

    /**
     * Rebuild the event from a decoded wire record.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static;
}
