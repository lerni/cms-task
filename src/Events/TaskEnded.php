<?php

namespace Kraftausdruck\Events;

/**
 * Emitted (CLI side) by bin/background-executor as the terminal JSONL line
 * written to the stream file when the subprocess exits.
 *
 * Replayed (FPM side) by TaskStreamController when it reads that terminal line,
 * decoded here via fromArray() and dispatched through a PSR-14 dispatcher.
 *
 * reason values:
 *  - 'completed' — subprocess exited with code 0
 *  - 'failed'    — subprocess exited with a non-zero code
 *  - 'aborted'   — executor threw a \Throwable before the subprocess finished
 *
 * Serializable value object — scalars/IDs only. No live objects, no closures.
 * The wire format is a versioned contract; bump WIRE_VERSION on breaking changes.
 */
final class TaskEnded implements TaskEvent
{
    public const WIRE_VERSION = 1;

    public function __construct(
        public readonly string $taskId,
        public readonly string $commandName,
        public readonly ?string $scopeKey,
        public readonly ?int $memberId,
        public readonly string $reason,
        public readonly int $endedAt,
        public readonly ?int $exitCode,
    ) {
    }

    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'command_name' => $this->commandName,
            'scope_key' => $this->scopeKey,
            'member_id' => $this->memberId,
            'reason' => $this->reason,
            'ended_at' => $this->endedAt,
            'exit_code' => $this->exitCode,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            (string) $data['task_id'],
            (string) $data['command_name'],
            isset($data['scope_key']) ? (string) $data['scope_key'] : null,
            isset($data['member_id']) ? (int) $data['member_id'] : null,
            (string) $data['reason'],
            (int) $data['ended_at'],
            isset($data['exit_code']) ? (int) $data['exit_code'] : null,
        );
    }
}
