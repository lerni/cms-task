<?php

namespace Kraftausdruck\Tasks;

use SilverStripe\PolyExecution\PolyOutput;
use Kraftausdruck\Contracts\TaskProgressStoreInterface;

/**
 * PolyOutput decorator that writes task output to a stream file (JSONL)
 * and updates structured metadata in the progress store.
 *
 * The stream file is the real-time delivery channel (read by the SSE endpoint).
 * The store metadata is for progress state, reconnection, and the service API.
 *
 * Available for tasks that want explicit progress reporting via
 * setTotalSteps() / advanceStep(). Also captures all writeln() output
 * transparently for tasks that don't know about progress tracking.
 */
class ProgressTrackingOutput
{
    private PolyOutput $wrappedOutput;

    private TaskProgressStoreInterface $store;

    private string $taskId;

    /** @var resource|false */
    private $streamHandle;

    private int $currentStep = 0;

    private int $totalSteps = 0;

    public function __construct(
        PolyOutput $wrappedOutput,
        TaskProgressStoreInterface $store,
        string $taskId,
    ) {
        $this->wrappedOutput = $wrappedOutput;
        $this->store = $store;
        $this->taskId = $taskId;
        $this->streamHandle = fopen($store->getStreamFilePath($taskId), 'a');
    }

    public function __destruct()
    {
        if (is_resource($this->streamHandle)) {
            fclose($this->streamHandle);
        }
    }

    /**
     * Delegate unknown method calls to the wrapped output.
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->wrappedOutput->$method(...$args);
    }

    public function writeln(string|iterable $messages, int $options = 0): void
    {
        $this->wrappedOutput->writeln($messages, $options);

        $messages = is_iterable($messages) ? $messages : [$messages];
        foreach ($messages as $message) {
            $this->appendToStream((string) $message);
        }

        $this->syncMetadata();
    }

    public function write(string|iterable $messages, bool $newline = false, int $options = 0): void
    {
        $this->wrappedOutput->write($messages, $newline, $options);

        if ($newline) {
            $messages = is_iterable($messages) ? $messages : [$messages];
            foreach ($messages as $message) {
                $this->appendToStream((string) $message);
            }

            $this->syncMetadata();
        }
    }

    public function setTotalSteps(int $total): void
    {
        $this->totalSteps = $total;
        $this->syncMetadata();
    }

    public function advanceStep(int $steps = 1): void
    {
        $this->currentStep += $steps;
        $this->syncMetadata();
    }

    public function setCurrentStep(int $step): void
    {
        $this->currentStep = $step;
        $this->syncMetadata();
    }

    public function getWrappedOutput(): PolyOutput
    {
        return $this->wrappedOutput;
    }

    /**
     * Append a single JSONL line to the stream file.
     */
    private function appendToStream(string $message): void
    {
        if (!is_resource($this->streamHandle)) {
            return;
        }

        // Strip ANSI escape codes for storage
        $clean = preg_replace('/\e\[[0-9;]*m/', '', $message);

        $line = json_encode([
            'text' => $clean,
            'type' => $this->detectType($clean),
            'ts' => time(),
        ], JSON_UNESCAPED_UNICODE) . "\n";

        fwrite($this->streamHandle, $line);
        fflush($this->streamHandle);
    }

    /**
     * Push current step/progress metadata to the store.
     */
    private function syncMetadata(): void
    {
        $progress = ($this->totalSteps > 0)
            ? min(100, round(($this->currentStep / $this->totalSteps) * 100, 1))
            : 0;

        $this->store->updateTask($this->taskId, [
            'progress' => $progress,
            'current_step' => $this->currentStep,
            'total_steps' => $this->totalSteps,
            'status' => 'running',
        ]);
    }

    private function detectType(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'error') || str_contains($lower, 'failed') || str_contains($lower, '✗')) {
            return 'error';
        }

        if (str_contains($lower, 'warning') || str_contains($lower, 'warn')) {
            return 'warning';
        }

        if (str_contains($lower, 'success') || str_contains($lower, '✓') || str_contains($lower, 'completed')) {
            return 'success';
        }

        return 'info';
    }
}
