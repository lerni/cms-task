<?php

namespace Kraftausdruck\Controller;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Permission;
use SilverStripe\Core\Injector\Injector;
use Kraftausdruck\Contracts\TaskProgressStoreInterface;

/**
 * Lightweight controller that serves Server-Sent Events (SSE)
 * for real-time task progress streaming.
 *
 * Registered at /task-stream/{TaskID} via Director rules.
 *
 * Uses the tail-f pattern: reads the stream file line by line,
 * sends each line as an SSE event instantly, sleeps briefly at EOF.
 * PSR cache is only consulted for metadata (status, completion check)
 * and for reconnection (resume from Last-Event-ID byte position).
 */
class TaskStreamController extends Controller
{
    private static $url_segment = 'task-stream';

    /**
     * Padding size in bytes to overcome reverse proxy buffering (e.g. Apache proxy_fcgi).
     * SSE comments are used as padding and silently ignored by EventSource.
     * Set to 0 to disable (e.g. if your proxy already flushes immediately).
     */
    private static $flush_padding_bytes = 4096;

    private static $allowed_actions = [
        'stream',
    ];

    private static $url_handlers = [
        '$TaskID' => 'stream',
    ];

    public function stream(HTTPRequest $request): void
    {
        if (!Permission::check('CMS_ACCESS')) {
            $this->httpError(403, 'Not authorised');

            return;
        }

        $taskId = $request->param('TaskID');
        if (!$taskId) {
            $this->httpError(400, 'Task ID required');

            return;
        }

        /** @var TaskProgressStoreInterface $store */
        $store = Injector::inst()->get(TaskProgressStoreInterface::class);

        $meta = $store->getTask($taskId);
        if (!$meta) {
            $this->httpError(404, 'Task not found');

            return;
        }

        // Disable all output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Resume position from browser's automatic Last-Event-ID
        $resumePosition = 0;
        $lastEventId = $request->getHeader('Last-Event-ID');
        if ($lastEventId !== null && is_numeric($lastEventId)) {
            $resumePosition = (int) $lastEventId;
        }

        // Send current metadata snapshot (useful on reconnect)
        $this->sendEvent('meta', $meta);

        // Wait for stream file to appear (process might still be starting)
        $streamFile = $store->getStreamFilePath($taskId);
        $waitStart = time();
        while (!file_exists($streamFile) && time() - $waitStart < 10) {
            $this->sendComment('waiting');
            usleep(500000);
            if (connection_aborted()) {
                return;
            }
        }

        if (!file_exists($streamFile)) {
            $this->sendEvent('error', ['message' => 'Stream file not available']);

            return;
        }

        $handle = fopen($streamFile, 'r');
        if (!$handle) {
            $this->sendEvent('error', ['message' => 'Cannot open stream file']);

            return;
        }

        if ($resumePosition > 0) {
            fseek($handle, $resumePosition);
        }

        $maxDuration = 300; // 5 min safety limit
        $startTime = time();

        while (!connection_aborted() && (time() - $startTime) < $maxDuration) {
            $line = fgets($handle);

            if ($line !== false) {
                // Data available — send immediately, no delay
                $position = ftell($handle);
                echo "id: {$position}\n";
                echo "event: output\n";
                echo 'data: ' . trim($line) . "\n\n";
                $this->flushWithPadding();
            } else {
                // EOF — check if task finished
                $meta = $store->getTask($taskId);
                if ($meta && !empty($meta['completed'])) {
                    $this->sendEvent('finished', $meta);

                    break;
                }

                // Clear PHP's internal EOF flag so fgets() can see
                // newly appended data (like clearerr() in C)
                fseek($handle, ftell($handle));

                // Keepalive + brief sleep (this is how tail -f works)
                $this->sendComment('keepalive');
                usleep(200000); // 200ms
            }
        }

        fclose($handle);

        // Must exit to prevent Silverstripe from sending additional response data
        exit();
    }

    private function sendEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        $this->flushWithPadding();
    }

    private function sendComment(string $comment): void
    {
        echo ": {$comment}\n\n";
        flush();
    }

    private function flushWithPadding(): void
    {
        $padding = static::config()->get('flush_padding_bytes');
        if ($padding > 0) {
            echo ': ' . str_repeat(' ', $padding) . "\n\n";
        }
        flush();
    }
}
