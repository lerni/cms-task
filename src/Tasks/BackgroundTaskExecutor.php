<?php

namespace Kraftausdruck\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Kraftausdruck\Contracts\TaskProgressStoreInterface;

/**
 * Background Task Executor
 *
 * Runs any BuildTask as a subprocess via sake, captures stdout line
 * by line, writes JSONL to a stream file, and updates structured
 * metadata in the progress store.
 *
 * Invoked by BackgroundTaskService as a detached CLI process:
 *   sake tasks:background-executor --target-task=ping-google --task-id=abc123
 */
class BackgroundTaskExecutor extends BuildTask
{
    protected static string $commandName = 'background-executor';

    protected string $title = 'Background Task Executor';

    protected static string $description = 'Executes a BuildTask in the background with stream-file progress tracking';

    public function getOptions(): array
    {
        return [
            new InputOption(
                'target-task',
                't',
                InputOption::VALUE_REQUIRED,
                'The BuildTask command name to execute',
            ),
            new InputOption(
                'task-options',
                'o',
                InputOption::VALUE_OPTIONAL,
                'JSON encoded options to pass to the target task',
                '{}',
            ),
            new InputOption(
                'task-id',
                'i',
                InputOption::VALUE_REQUIRED,
                'Unique identifier for this background task execution',
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $targetTask = $input->getOption('target-task');
        $taskOptions = json_decode($input->getOption('task-options'), true) ?: [];
        $taskId = $input->getOption('task-id');

        if (!$targetTask || !$taskId) {
            $output->writeln('<error>--target-task and --task-id are required</error>');

            return Command::FAILURE;
        }

        /** @var TaskProgressStoreInterface $store */
        $store = Injector::inst()->get(TaskProgressStoreInterface::class);

        try {
            // Read-then-merge: update existing entry, don't overwrite
            $store->updateTask($taskId, [
                'status' => 'running',
                'message' => "Resolving task {$targetTask}",
            ]);

            // Find the target task class
            $taskClass = $this->findTaskClass($targetTask);
            if (!$taskClass) {
                $store->updateTask($taskId, [
                    'status' => 'failed',
                    'completed' => true,
                    'message' => "Task '{$targetTask}' not found",
                ]);
                $store->removeFromActiveIndex($taskId);
                $output->writeln("<error>Task '{$targetTask}' not found</error>");

                return Command::FAILURE;
            }

            $output->writeln("<info>Executing: {$targetTask}</info>");
            $store->updateTask($taskId, [
                'status' => 'running',
                'message' => "Executing {$targetTask}",
            ]);

            // Build and run the sake command, capturing output to stream file
            $sakeCommand = $this->buildSakeCommand($targetTask, $taskOptions);
            $exitCode = $this->runAndStream($sakeCommand, $taskId, $store, $output);

            // Final status
            $finalStatus = $exitCode === 0 ? 'completed' : 'failed';
            $store->updateTask($taskId, [
                'status' => $finalStatus,
                'exit_code' => $exitCode,
                'completed' => true,
                'message' => $exitCode === 0 ? 'Task completed successfully' : 'Task failed',
            ]);
            $store->removeFromActiveIndex($taskId);

            $output->writeln(
                $exitCode === 0
                    ? '<info>Task completed successfully</info>'
                    : '<error>Task failed</error>',
            );

            return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $store->updateTask($taskId, [
                'status' => 'failed',
                'completed' => true,
                'message' => $e->getMessage(),
            ]);
            $store->removeFromActiveIndex($taskId);
            $output->writeln("<error>Execution failed: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }
    }

    /**
     * Run the sake subprocess and stream its stdout to the stream file.
     */
    private function runAndStream(
        string $command,
        string $taskId,
        TaskProgressStoreInterface $store,
        PolyOutput $output,
    ): int {
        $streamFile = $store->getStreamFilePath($taskId);
        $streamHandle = fopen($streamFile, 'a');

        if (!$streamHandle) {
            $output->writeln('<error>Cannot open stream file</error>');

            return 1;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            fclose($streamHandle);
            $output->writeln('<error>Failed to start subprocess</error>');

            return 1;
        }

        fclose($pipes[0]); // close stdin

        // Read stdout/stderr in real-time, write JSONL to stream file
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, 100000) > 0) {
                foreach ($read as $stream) {
                    $line = fgets($stream);
                    if ($line === false) {
                        continue;
                    }

                    $line = rtrim($line);
                    if ($line === '') {
                        continue;
                    }

                    // Forward to executor's own output
                    $output->writeln($line);

                    $clean = preg_replace('/\e\[[0-9;]*m/', '', $line);

                    // Extract progress (also updates cache store)
                    $progress = $this->extractProgress($clean, $taskId, $store);

                    // Write JSONL to stream file (include progress when detected)
                    $data = [
                        'text' => $clean,
                        'type' => $this->detectLineType($clean),
                        'ts' => time(),
                    ];
                    if ($progress !== null) {
                        $data['progress'] = $progress;
                    }
                    $jsonLine = json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
                    fwrite($streamHandle, $jsonLine);
                    fflush($streamHandle);
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        fclose($streamHandle);

        return proc_close($process);
    }

    /**
     * Extract step/progress data from known output patterns.
     * Returns the progress percentage if found, null otherwise.
     */
    private function extractProgress(string $line, string $taskId, TaskProgressStoreInterface $store): ?float
    {
        if (preg_match('/Processing step (\d+)\/(\d+)/', $line, $m)) {
            $current = (int) $m[1];
            $total = (int) $m[2];
            $progress = round(($current / $total) * 100, 1);

            $store->updateTask($taskId, [
                'current_step' => $current,
                'total_steps' => $total,
                'progress' => $progress,
                'message' => $line,
            ]);

            return $progress;
        } elseif (preg_match('/Progress:\s*(\d+(?:\.\d+)?)%/', $line, $m)) {
            $progress = (float) $m[1];

            $store->updateTask($taskId, [
                'progress' => $progress,
                'message' => $line,
            ]);

            return $progress;
        }

        return null;
    }

    private function detectLineType(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'error') || str_contains($lower, 'failed') || str_contains($lower, '✗')) {
            return 'error';
        }

        if (str_contains($lower, 'warning')) {
            return 'warning';
        }

        if (str_contains($lower, 'success') || str_contains($lower, '✓')) {
            return 'success';
        }

        return 'info';
    }

    private function findTaskClass(string $taskName): ?string
    {
        if (class_exists($taskName) && is_subclass_of($taskName, BuildTask::class)) {
            return $taskName;
        }

        foreach (ClassInfo::subclassesFor(BuildTask::class) as $class) {
            if ($class === BuildTask::class) {
                continue;
            }

            try {
                $prop = (new \ReflectionClass($class))->getProperty('commandName');
                if ($prop->getValue() === $taskName) {
                    return $class;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    private function buildSakeCommand(string $targetTask, array $options): string
    {
        // todo - check again
        // stdbuf -oL forces line-buffered stdout so output streams
        // line-by-line instead of being block-buffered in the pipe
        // $sake = 'stdbuf -oL php ' . BASE_PATH . '/vendor/bin/sake';
        $sake = 'php ' . BASE_PATH . '/vendor/bin/sake';

        if (str_contains($targetTask, '\\')) {
            $class = $this->findTaskClass($targetTask);
            if ($class) {
                $prop = (new \ReflectionClass($class))->getProperty('commandName');
                $targetTask = $prop->getValue();
            }
        }

        $cmd = "{$sake} tasks:{$targetTask}";

        foreach ($options as $key => $value) {
            $cmd .= ' --' . $key . '=' . escapeshellarg((string) $value);
        }

        return $cmd;
    }
}
