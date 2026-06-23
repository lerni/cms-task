# lerni/cms-task

STATUS: **POC**, basically working implementation - many ideas still floating.

Silverstripe background BuildTask execution with real-time UI progress.

Tasks run as **detached CLI processes** (via `sake`), so the browser never blocks. Progress is streamed in real-time through JSONL files read by an SSE endpoint. A PSR-16 cache stores task metadata for status recovery.

## How it works

```
Browser (CMS)                    Server
─────────────────────────────────────────────
BackgroundTaskField  ──POST──▶  BackgroundTaskService
  "start task"                    ├─ store->initTask()
                                  └─ startBackgroundTask() ──▶ detached CLI
                                                          │
                                                          │
EventSource  ◀──SSE──  TaskStreamController               ▼
  (tail-f read)          reads .stream file   ◀── bin/background-executor
  ← event: output                                  writes JSONL per line
  ← event: task_ended  ◀── decoded + PSR-14        writes {"type":"__cms_task_ended",...} at exit
```

## File overview

```
cms-task/
├── _config/backgroundtasks.yml          # Injector bindings + Director routes
├── bin/
│   └── background-executor             # Standalone executor script (no SS bootstrap)
├── client/dist/
│   ├── css/background-task-field.css    # CMS field styles
│   └── js/background-task-field.js      # EventSource + fetch, event-delegated
├── composer.json
├── src/
│   ├── Admin/TaskRunnerAdmin.php        # Demo admin panel (SingleRecordAdmin)
│   ├── Contracts/
│   │   └── TaskProgressStoreInterface.php  # Storage abstraction
│   ├── Controller/
│   │   └── TaskStreamController.php     # SSE endpoint at /task-stream/{TaskID}
│   ├── Events/
│   │   ├── TaskEnded.php               # Terminal event (CLI→FPM wire + PSR-14)
│   │   ├── TaskStarted.php             # Fresh spawn confirmed (FPM side)
│   │   ├── TaskStartThrottled.php      # Start rejected by rate limiter (FPM side)
│   │   └── TaskEvent.php              # Serialization contract (toArray/fromArray)
│   ├── Fields/
│   │   └── BackgroundTaskField.php      # Self-contained FormField (start/stop)
│   ├── Models/
│   │   └── TaskRunnerConfig.php         # DataObject for the demo admin
│   ├── Services/
│   │   └── BackgroundTaskService.php    # Task lifecycle: start, stop, query
│   ├── Stores/
│   │   └── PsrCacheProgressStore.php    # FilesystemAdapter cache + /tmp stream files
│   └── Tasks/
│       └── PingGoogleTask.php           # Demo task — pings google.com N times
└── templates/
    └── Kraftausdruck/Fields/
        └── BackgroundTaskField.ss       # Field template with data attributes
```

## Key components

### BackgroundTaskField

A `FormField` you drop into any SS form. Provides its own `start` and `stop` HTTP actions. JS uses native `EventSource` for real-time streaming output and progress.

### TaskStreamController

Registered at `/task-stream/$TaskID`. Reads the JSONL stream file line-by-line (tail-f pattern) and sends each line as an SSE event. Supports `Last-Event-ID` for reconnection. Configurable flush padding (`$flush_padding_bytes`) to overcome proxy buffering (e.g. Apache `mod_proxy_fcgi`).

### BackgroundTaskService

Shared service for the task lifecycle. Resolves task command names to classes, spawns the detached executor script, and manages the progress store.

### PsrCacheProgressStore

Uses Symfony's `FilesystemAdapter` directly (bypasses Silverstripe's `CacheFactory` to ensure cache visibility across CLI and web processes). Stream files live at `{TEMP_PATH}/ss_background_tasks/` (falls back to `sys_get_temp_dir()` if `TEMP_PATH` is not defined). The cache holds task metadata; the stream file is the real-time delivery channel.

### bin/background-executor

Standalone PHP script that runs a BuildTask as a subprocess via `sake` + `proc_open`. Captures stdout line-by-line, writes JSONL to the stream file, and extracts progress from output patterns like `Processing step X/Y` or `Progress: XX%`. Does not boot Silverstripe — only needs composer autoload and the `PsrCacheProgressStore` class.

## Usage

Add `BackgroundTaskField` to the interface to your likes.

```php
use Kraftausdruck\Fields\BackgroundTaskField;

$fields->addFieldToTab('Root.Tasks', BackgroundTaskField::create(
    'MyTask',           // field name (unique per form)
    'my-command-name',  // BuildTask $commandName
    ['option' => 'val'],// CLI options passed to the task
    'Run My Task',      // button label
));
```

### Scope key

Use `setScopeKey()` to tie a task to a specific context (e.g. a record ID). This controls two things:

- **Deduplication**: a new start request reuses an already-running task with the same command + scope instead of spawning a duplicate.
- **Recovery**: after a page reload or PJAX navigation, the field reconnects to the running task matching its command + scope.

```php
BackgroundTaskField::create('MyTask', 'my-command-name')
    ->setScopeKey('Page_' . $this->owner->ID);
```

The scope key also determines **visibility across users**. A scope like `Page_42` means any CMS user editing that page will see the running task and its output. To isolate tasks per user, include the member ID:

```php
->setScopeKey('Page_' . $this->owner->ID . '_Member_' . Security::getCurrentUser()->ID);
```

Without a scope key, dedup and recovery match on the command name alone.

### Rate limiting

`BackgroundTaskService` rate-limits fresh task spawns to prevent rapid restart cycles after a task completes. The limit is keyed on `commandName + scopeKey`, so it shares the **same knob** as dedup and recovery:

| Scope key | Rate limit scope |
|---|---|
| `null` (default) | global per command — one budget for all users |
| `'my-command'` (fixed string) | global — same as above |
| `'my-command_Member_42'` (includes user) | per-user — each member gets their own budget |

Default: **1 fresh spawn per 2 minutes** per scope. Configure globally via YAML:

```yaml
Kraftausdruck\Services\BackgroundTaskService:
  start_rate_limit: 1  # max fresh spawns per window (0 = disabled)
  start_rate_decay: 2  # window length in minutes
```

Programmatic callers can override per call by passing `$rateLimitMaxAttempts` / `$rateLimitDecay` to `BackgroundTaskService::startBackgroundTask()`.

**Important:** reconnecting to an already-running task does not touch the rate limiter at all. The field calls `findActiveTask()` and returns the existing task **before** the service's limiter runs, so the limiter only ever gates genuine fresh spawns. Because the limit lives in the service, it also covers programmatic callers (not just the CMS field).

### Lifecycle events (PSR-14)

The module emits serializable value objects at key lifecycle points. Events are dispatched through an **optional** PSR-14 `EventDispatcherInterface` — if none is bound in the Injector, dispatch is a **no-op**.

#### FPM-side events (dispatched by `BackgroundTaskService`)

- `Kraftausdruck\Events\TaskStarted` — a fresh spawn was confirmed.
- `Kraftausdruck\Events\TaskStartThrottled` — a start was rejected by the rate limiter (carries `retryAfter`).

#### Terminal event — crosses the process boundary via the JSONL stream

- `Kraftausdruck\Events\TaskEnded` — the subprocess exited. `reason` is `completed`, `failed`, or `aborted`.

`bin/background-executor` writes `{"type":"__cms_task_ended","data":{...}}` as the **last line** of the stream file when the subprocess exits (in a `finally` block, so it fires on completion, failure, and uncaught exception). It writes this terminal line **before** flipping the cache `completed` flag, so a connected reader always sees `task_ended` rather than racing the `finished` fallback. `TaskStreamController` reads that line, sends it to the browser as `event: task_ended`, and replays it locally through PSR-14.

> **Reserved type:** `__cms_task_ended` is a reserved control sentinel on the stream. Don't emit a JSONL line with that `type` from your own task output (see below) — the reader treats it as the terminal event and stops streaming. Use any other `type` (e.g. `progress`, `result`).

> **Note:** the FPM-side `TaskEnded` dispatch only fires when a reader (browser EventSource or MCP server) is connected to the SSE stream at the time the task ends. For headless callers, poll `BackgroundTaskService::getTask($taskId)['completed']` instead.

#### Structured task output (`--format=json`)

If a task prints a JSONL line carrying a `type` key (e.g. `{"type":"progress","current":3,"total":17}`), the executor forwards it to the stream **verbatim** instead of wrapping it as a text line. This is the channel an `--format=json` task uses to emit structured progress for an MCP client. Note that such lines bypass the executor's `Processing step X/Y` / `Progress: XX%` text scraping, so they do **not** update the `progress`/`message` fields in the cache metadata — they reach readers via the SSE stream only. A client polling `getTask()` for `progress` won't see updates from json-mode tasks; watch the stream (or `completed`) instead.

#### Wiring a listener

`symfony/event-dispatcher` is already in the dependency tree via `silverstripe/framework`. Bind it and register listeners via YAML:

```yaml
SilverStripe\Core\Injector\Injector:
  Psr\EventDispatcher\EventDispatcherInterface:
    class: Symfony\Component\EventDispatcher\EventDispatcher
    calls:
      - [addListener, ['Kraftausdruck\Events\TaskEnded', '%$App\TaskEndedListener']]
```

```php
// app/src/TaskEndedListener.php
class TaskEndedListener
{
    public function __invoke(TaskEnded $event): void
    {
        if ($event->commandName !== 'my-command') {
            return;
        }

        if ($event->reason !== 'completed') {
            return;
        }

        // do the other thing
    }
}
```

All event classes are **serializable value objects** (scalars/IDs only — no live objects, no closures) and implement `TaskEvent` (`toArray()` / `fromArray()` / `WIRE_VERSION`).

A demo admin is available at `/admin/task-runner` via `TaskRunnerAdmin`. It'll be removed as we approach a stable release. Meantime it can be hidden:

```yaml
Kraftausdruck\Admin\TaskRunnerAdmin:
  ignore_menuitem: true
```

## Installation

```bash
composer require lerni/cms-task
sake db:build --flush
```

## PHP CLI Binary

Tasks run as detached CLI processes via `sake`. Silverstripe uses `PHP_BINARY` to locate the PHP executable. By default, the module will attempt to automatically detect it. However, if the correct binary isn't in the web server's `$PATH`, you can explicitly define it:

```dotenv
SS_PHP_CLI_BINARY="/usr/bin/php"
```

**It is crucial that the CLI binary runs the same PHP version as your web server.** Mismatched versions can cause subtle errors — different extensions loaded, different behaviour, or outright failures.

## Requirements

- Silverstripe Framework ^6
- Silverstripe Admin ^3
- psr/event-dispatcher ^1 (implicit)
