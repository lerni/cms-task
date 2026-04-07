<?php

namespace Kraftausdruck\Stores;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Kraftausdruck\Contracts\TaskProgressStoreInterface;

/**
 * PSR-16 cache backed progress store.
 *
 * Metadata lives in cache; stream files live on the local filesystem.
 * The cache is the recovery/metadata store; the stream file is the
 * real-time delivery channel (read via tail-f pattern by the SSE endpoint).
 *
 * Uses FilesystemAdapter explicitly instead of Silverstripe's CacheFactory
 * because the default PhpFilesAdapter caches values in PHP's opcache,
 * which is per-SAPI — writes from CLI (executor) are invisible to
 * FPM (SSE controller) and vice versa.
 */
class PsrCacheProgressStore implements TaskProgressStoreInterface
{
    private CacheInterface $cache;

    private string $streamDir;

    private const ACTIVE_INDEX_KEY = 'background_tasks_active';

    private const TASK_PREFIX = 'task_progress_';

    private const TTL = 3600;

    public function __construct(?string $cacheDir = null)
    {
        $cacheDir = $cacheDir ?? (defined('TEMP_PATH') ? TEMP_PATH : sys_get_temp_dir());
        $psr6 = new FilesystemAdapter('BackgroundTasks', self::TTL, $cacheDir);
        $this->cache = new Psr16Cache($psr6);
        $this->streamDir = rtrim($cacheDir, '/') . '/ss_background_tasks';

        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0750, true);
        }
    }

    public function initTask(string $taskId, array $metadata): void
    {
        $metadata['task_id'] = $taskId;
        $metadata['stream_file'] = $this->getStreamFilePath($taskId);

        $this->cache->set(self::TASK_PREFIX . $taskId, $metadata, self::TTL);

        // Create (or truncate) the stream file
        file_put_contents($this->getStreamFilePath($taskId), '');
    }

    public function updateTask(string $taskId, array $updates): void
    {
        $existing = $this->cache->get(self::TASK_PREFIX . $taskId, []);
        $data = array_merge($existing, $updates, ['updated_at' => time()]);

        $this->cache->set(self::TASK_PREFIX . $taskId, $data, self::TTL);
    }

    public function getTask(string $taskId): ?array
    {
        return $this->cache->get(self::TASK_PREFIX . $taskId);
    }

    public function deleteTask(string $taskId): void
    {
        $this->cache->delete(self::TASK_PREFIX . $taskId);

        $streamFile = $this->getStreamFilePath($taskId);
        if (file_exists($streamFile)) {
            unlink($streamFile);
        }

        $this->removeFromActiveIndex($taskId);
    }

    public function getActiveTaskIds(): array
    {
        return $this->cache->get(self::ACTIVE_INDEX_KEY, []);
    }

    public function addToActiveIndex(string $taskId): void
    {
        $active = $this->getActiveTaskIds();

        if (!in_array($taskId, $active)) {
            $active[] = $taskId;
            $this->cache->set(self::ACTIVE_INDEX_KEY, $active, self::TTL);
        }
    }

    public function removeFromActiveIndex(string $taskId): void
    {
        $active = $this->getActiveTaskIds();
        $active = array_values(array_filter($active, fn ($id) => $id !== $taskId));

        $this->cache->set(self::ACTIVE_INDEX_KEY, $active, self::TTL);
    }

    public function findActiveTaskByCommand(string $commandName, ?string $scopeKey = null): ?array
    {
        foreach ($this->getActiveTaskIds() as $taskId) {
            $meta = $this->getTask($taskId);
            if (!$meta || ($meta['command_name'] ?? null) !== $commandName) {
                continue;
            }

            if ($scopeKey !== null && ($meta['scope_key'] ?? null) !== $scopeKey) {
                continue;
            }

            return $meta;
        }

        return null;
    }

    public function getStreamFilePath(string $taskId): string
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $taskId);

        return $this->streamDir . '/' . $safeId . '.stream';
    }
}
