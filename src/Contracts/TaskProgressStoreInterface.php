<?php

namespace Kraftausdruck\Contracts;

/**
 * Abstraction for task progress storage.
 *
 * Manages structured metadata in a key-value store and provides
 * stream file paths for real-time output delivery.
 */
interface TaskProgressStoreInterface
{
    /**
     * Create a new task entry and its empty stream file.
     */
    public function initTask(string $taskId, array $metadata): void;

    /**
     * Merge updates into an existing task entry.
     */
    public function updateTask(string $taskId, array $updates): void;

    /**
     * Get task metadata, or null if not found.
     */
    public function getTask(string $taskId): ?array;

    /**
     * Delete a task entry and its stream file.
     */
    public function deleteTask(string $taskId): void;

    /**
     * Get all active (non-completed) task IDs.
     */
    public function getActiveTaskIds(): array;

    /**
     * Add a task ID to the active index.
     */
    public function addToActiveIndex(string $taskId): void;

    /**
     * Remove a task ID from the active index.
     */
    public function removeFromActiveIndex(string $taskId): void;

    /**
     * Find an active task by command name, optionally filtered by scope key.
     *
     * Returns the first matching task metadata or null.
     */
    public function findActiveTaskByCommand(string $commandName, ?string $scopeKey = null): ?array;

    /**
     * Get the filesystem path to the stream file for a task.
     */
    public function getStreamFilePath(string $taskId): string;
}
