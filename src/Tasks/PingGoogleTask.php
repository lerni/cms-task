<?php

namespace Kraftausdruck\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;

/**
 * A simple task that pings google.com multiple times to test background processing
 */
class PingGoogleTask extends BuildTask
{
    protected string $title = 'Ping Google Task';

    protected static string $description = 'Pings google.com 50 times with a delay between each ping to simulate a long-running task';

    protected static string $commandName = 'ping-google';

    /**
     * Common fallback locations for environments where ping is not on PATH.
     *
     * @var list<string>
     */
    private const PING_BINARY_FALLBACKS = [
        '/sbin/ping',
        '/usr/sbin/ping',
        '/bin/ping',
        '/usr/bin/ping',
    ];

    public function getOptions(): array
    {
        return [
            new InputOption(
                'count',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Number of pings to send (default: 50)',
                50,
            ),
            new InputOption(
                'delay',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Delay between pings in seconds (default: 1)',
                1,
            ),
            new InputOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                'Output format: text (default) or json (structured JSONL per line)',
                'text',
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $count = (int) $input->getOption('count');
        $delay = (int) $input->getOption('delay');
        $format = (string) $input->getOption('format');
        $jsonMode = $format === 'json';

        if (!$jsonMode) {
            $output->writeln("<info>Starting to ping google.com {$count} times with {$delay}s delay...</info>");
            $output->writeln('');
        }

        $successCount = 0;
        $failCount = 0;

        for ($i = 1; $i <= $count; $i++) {
            $startTime = microtime(true);
            $result = $this->pingHost('google.com');
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            if ($result['success']) {
                $successCount++;
                if ($jsonMode) {
                    $output->writeln(json_encode(['type' => 'progress', 'current' => $i, 'total' => $count, 'ms' => $responseTime]));
                } else {
                    $output->write("Ping {$i}/{$count}: ");
                    $output->writeln("<info>✓ Success ({$responseTime}ms)</info>");
                }
            } else {
                $failCount++;
                if ($jsonMode) {
                    $output->writeln(json_encode(['type' => 'progress', 'current' => $i, 'total' => $count, 'error' => $result['error']]));
                } else {
                    $output->write("Ping {$i}/{$count}: ");
                    $output->writeln("<error>✗ Failed - {$result['error']}</error>");
                }
            }

            // Show progress (text mode only — json mode already emits per-ping progress above)
            if (!$jsonMode) {
                $progress = round(($i / $count) * 100, 1);
                $output->writeln("<comment>Progress: {$progress}% ({$i}/{$count})</comment>");
            }

            // Add delay between pings (except for the last one)
            if ($i < $count && $delay > 0) {
                if (!$jsonMode) {
                    $output->writeln("Waiting {$delay}s...");
                }

                sleep($delay);
            }

            if (!$jsonMode) {
                $output->writeln('');
            }
        }

        if ($jsonMode) {
            $output->writeln(json_encode(['type' => 'result', 'summary' => "Pings: {$count}, OK: {$successCount}, failed: {$failCount}"]));
        } else {
            // Summary
            $output->writeln('<options=bold>Results Summary:</options=bold>');
            $output->writeln("Total pings: {$count}");
            $output->writeln("<info>Successful: {$successCount}</info>");

            if ($failCount > 0) {
                $output->writeln("<error>Failed: {$failCount}</error>");
            }

            $successRate = round(($successCount / $count) * 100, 1);
            $output->writeln("Success rate: {$successRate}%");
        }

        return $failCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Ping a host and return result.
     *
     * @return array<string, mixed>
     */
    private function pingHost(string $host): array
    {
        try {
            $ping = $this->resolvePingBinary();

            if ($ping === null) {
                return [
                    'success' => false,
                    'error' => 'No ping binary found on PATH or in common locations.',
                ];
            }

            $osFamily = PHP_OS_FAMILY;
            $escapedPing = escapeshellarg($ping);
            $escapedHost = escapeshellarg($host);

            if ($osFamily === 'Windows') {
                $command = "{$escapedPing} -n 1 {$escapedHost}";
            } elseif ($osFamily === 'Linux') {
                $command = "{$escapedPing} -c 1 -W 5 {$escapedHost}";
            } else {
                $command = "{$escapedPing} -c 1 {$escapedHost}";
            }

            $output = [];
            $returnCode = 0;
            exec($command . ' 2>&1', $output, $returnCode);

            if ($returnCode === 0) {
                return [
                    'success' => true,
                    'output' => implode("\n", $output),
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Ping failed (return code: ' . $returnCode . ')',
                    'output' => implode("\n", $output),
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function resolvePingBinary(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'ping';
        }

        foreach (self::PING_BINARY_FALLBACKS as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
