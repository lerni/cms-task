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
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $count = (int) $input->getOption('count');
        $delay = (int) $input->getOption('delay');

        $output->writeln("<info>Starting to ping google.com {$count} times with {$delay}s delay...</info>");
        $output->writeln('');

        $successCount = 0;
        $failCount = 0;

        for ($i = 1; $i <= $count; $i++) {
            $output->write("Ping {$i}/{$count}: ");

            // Execute ping command
            $startTime = microtime(true);
            $result = $this->pingHost('google.com');
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            if ($result['success']) {
                $successCount++;
                $output->writeln("<info>✓ Success ({$responseTime}ms)</info>");
            } else {
                $failCount++;
                $output->writeln("<error>✗ Failed - {$result['error']}</error>");
            }

            // Show progress
            $progress = round(($i / $count) * 100, 1);
            $output->writeln("<comment>Progress: {$progress}% ({$i}/{$count})</comment>");

            // Add delay between pings (except for the last one)
            if ($i < $count && $delay > 0) {
                $output->writeln("Waiting {$delay}s...");
                sleep($delay);
            }

            $output->writeln('');
        }

        // Summary
        $output->writeln('<options=bold>Results Summary:</options=bold>');
        $output->writeln("Total pings: {$count}");
        $output->writeln("<info>Successful: {$successCount}</info>");

        if ($failCount > 0) {
            $output->writeln("<error>Failed: {$failCount}</error>");
        }

        $successRate = round(($successCount / $count) * 100, 1);
        $output->writeln("Success rate: {$successRate}%");

        return $failCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Ping a host and return result
     */
    private function pingHost(string $host): array
    {
        try {
            // Use different ping commands based on OS
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

            if ($isWindows) {
                $command = "ping -n 1 {$host}";
            } else {
                $command = "ping -c 1 -W 5 {$host}";
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
}
