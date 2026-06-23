<?php

namespace Kraftausdruck\Events;

/**
 * Dispatched (FPM side) when a fresh background task spawn is confirmed.
 *
 * Serializable value object — scalars/IDs only.
 */
final class TaskStarted implements TaskEvent
{
    public const WIRE_VERSION = 1;

    public function __construct(
        public readonly string $taskId,
        public readonly string $commandName,
        public readonly ?string $scopeKey,
        public readonly ?int $memberId,
        public readonly int $startedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'command_name' => $this->commandName,
            'scope_key' => $this->scopeKey,
            'member_id' => $this->memberId,
            'started_at' => $this->startedAt,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            (string) $data['task_id'],
            (string) $data['command_name'],
            isset($data['scope_key']) ? (string) $data['scope_key'] : null,
            isset($data['member_id']) ? (int) $data['member_id'] : null,
            (int) $data['started_at'],
        );
    }
}
