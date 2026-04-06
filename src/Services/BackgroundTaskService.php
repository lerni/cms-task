<?php

namespace Kraftausdruck\Services;

use SilverStripe\Dev\BuildTask;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\Injectable;
use Kraftausdruck\Contracts\TaskProgressStoreInterface;

/**
 * Service for managing background task execution and progress tracking.
 *
 * Spawns BuildTasks as detached CLI processes via executor script.
 * Progress is tracked through a TaskProgressStore (PSR cache by default)
 * and delivered to the browser via stream files (read by the SSE endpoint).
 *
 * This service is the shared core consumed by BackgroundTaskField (CMS UI).
 */
class BackgroundTaskService
{
    use Injectable;

    private TaskProgressStoreInterface $store;

    public function __construct()
    {
        $this->store = Injector::inst()->get(TaskProgressStoreInterface::class);
    }

    /**
     * Start a BuildTask in the background.
     *
     * @param string $taskName BuildTask command name (e.g. 'ping-google')
     * @param array<string, mixed> $options Options forwarded to the task CLI
     * @param string|null $taskId Custom task ID (auto-generated if null)
     * @param int|null $memberId ID of the Member who initiated the task
     * @param string|null $scopeKey Opaque key for recovery/dedup scoping
     * @return array{task_id: string, process_id: ?string, status: string} Task metadata
     */
    public function startBackgroundTask(
        string $taskName,
        array $options = [],
        ?string $taskId = null,
        ?int $memberId = null,
        ?string $scopeKey = null,
    ): array {
        if (!$taskId) {
            $taskId = 'task_' . bin2hex(random_bytes(12));
        }

        $resolvedClass = $this->resolveTaskClass($taskName);
        if (!$resolvedClass) {
            throw new \InvalidArgumentException("BuildTask '{$taskName}' not found");
        }

        // Init store entry + stream file
        $metadata = [
            'task_id' => $taskId,
            'task_class' => $resolvedClass,
            'command_name' => $taskName,
            'status' => 'starting',
            'started_at' => time(),
            'updated_at' => time(),
            'completed' => false,
            'progress' => 0,
            'message' => 'Task starting...',
            'options' => $options,
            'member_id' => $memberId,
            'scope_key' => $scopeKey,
        ];

        $this->store->initTask($taskId, $metadata);
        $this->store->addToActiveIndex($taskId);

        // Spawn detached CLI process
        $processId = $this->spawnExecutor($taskName, $options, $taskId);
        if ($processId) {
            $this->store->updateTask($taskId, ['process_id' => $processId]);
            $metadata['process_id'] = $processId;
        }

        return $metadata;
    }

    /**
     * Get task progress metadata.
     */
    public function getTaskProgress(string $taskId): ?array
    {
        return $this->store->getTask($taskId);
    }

    /**
     * Stop a running background task.
     */
    public function stopBackgroundTask(string $taskId): bool
    {
        $meta = $this->store->getTask($taskId);
        if (!$meta) {
            return false;
        }

        if (!empty($meta['process_id'])) {
            $this->killProcess((int) $meta['process_id']);
        }

        $this->store->updateTask($taskId, [
            'status' => 'stopped',
            'completed' => true,
            'message' => 'Task stopped by user',
        ]);
        $this->store->removeFromActiveIndex($taskId);

        return true;
    }

    /**
     * Get progress info for all active tasks.
     *
     * @return array<string, array>
     */
    public function getActiveTasks(): array
    {
        $tasks = [];
        foreach ($this->store->getActiveTaskIds() as $taskId) {
            $meta = $this->store->getTask($taskId);
            if ($meta) {
                $tasks[$taskId] = $meta;
            }
        }

        return $tasks;
    }

    /**
     * Find an active task by command name, optionally filtered by scope key.
     *
     * @param string $commandName BuildTask command name
     * @param string|null $scopeKey If provided, must also match scope_key in metadata
     */
    public function findActiveTask(string $commandName, ?string $scopeKey = null): ?array
    {
        return $this->store->findActiveTaskByCommand($commandName, $scopeKey);
    }

    /**
     * Get the store instance (needed by field for stream file path).
     */
    public function getStore(): TaskProgressStoreInterface
    {
        return $this->store;
    }

    private function resolveTaskClass(string $taskIdentifier): ?string
    {
        if (class_exists($taskIdentifier) && is_subclass_of($taskIdentifier, BuildTask::class)) {
            return $taskIdentifier;
        }

        foreach (ClassInfo::subclassesFor(BuildTask::class) as $class) {
            if ($class === BuildTask::class) {
                continue;
            }

            try {
                $prop = (new \ReflectionClass($class))->getProperty('commandName');
                if ($prop->getValue() === $taskIdentifier) {
                    return $class;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Spawn the background executor as a detached CLI process.
     *
     * Uses the standalone bin/background-executor script which only needs
     * composer autoload — no Silverstripe bootstrap. The target task
     * (invoked via sake) handles its own framework bootstrap.
     */
    private function spawnExecutor(string $taskName, array $options, string $taskId): ?string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : (Environment::getEnv('SS_BASE_PATH') ?: getcwd());
        $cacheDir = defined('TEMP_PATH') ? TEMP_PATH : sys_get_temp_dir();

        // Locate the executor script relative to this file (works for both
        // local path modules and composer-installed packages)
        $executorBin = dirname(__DIR__, 2) . '/bin/background-executor';

        $parts = [
            'php',
            escapeshellarg($executorBin),
            '--target-task=' . escapeshellarg($taskName),
            '--task-id=' . escapeshellarg($taskId),
            '--base-path=' . escapeshellarg($basePath),
            '--cache-dir=' . escapeshellarg($cacheDir),
        ];

        if (!empty($options)) {
            $parts[] = '--task-options=' . escapeshellarg(json_encode($options));
        }

        $command = implode(' ', $parts);

        if (function_exists('exec')) {
            return $this->startWithExec($command);
        }

        if (function_exists('proc_open')) {
            return $this->startWithProcOpen($command);
        }

        // Blocking fallback
        exec($command . ' > /dev/null 2>&1');

        return null;
    }

    private function startWithExec(string $command): ?string
    {
        $full = 'nohup ' . $command . ' > /dev/null 2>&1 & echo $!';
        $output = [];
        exec($full, $output);

        return !empty($output) ? trim($output[0]) : null;
    }

    private function startWithProcOpen(string $command): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command . ' > /dev/null 2>&1 &', $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $status = proc_get_status($process);
        $pid = $status['pid'] ?? null;

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);

        return $pid ? (string) $pid : null;
    }

    private function killProcess(int $processId): bool
    {
        if ($processId <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /PID ' . $processId . ' /F', $output, $rc);
        } else {
            exec('kill ' . $processId . ' 2>/dev/null', $output, $rc);
        }

        return $rc === 0;
    }
}
