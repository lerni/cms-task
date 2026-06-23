<?php

namespace Kraftausdruck\Events;

/**
 * Dispatched (FPM side) when a start request is rejected by the rate limiter.
 *
 * No task is spawned, so there is no task ID. Serializable value object —
 * scalars/IDs only. Lets observers (e.g. a future MCP server) surface a
 * "throttled" signal and map it to a retry-after response.
 */
final class TaskStartThrottled implements TaskEvent
{
    public const WIRE_VERSION = 1;

    public function __construct(
        public readonly string $commandName,
        public readonly ?string $scopeKey,
        public readonly ?int $memberId,
        public readonly int $retryAfter,
        public readonly int $throttledAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'command_name' => $this->commandName,
            'scope_key' => $this->scopeKey,
            'member_id' => $this->memberId,
            'retry_after' => $this->retryAfter,
            'throttled_at' => $this->throttledAt,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            (string) $data['command_name'],
            isset($data['scope_key']) ? (string) $data['scope_key'] : null,
            isset($data['member_id']) ? (int) $data['member_id'] : null,
            (int) $data['retry_after'],
            (int) $data['throttled_at'],
        );
    }
}
