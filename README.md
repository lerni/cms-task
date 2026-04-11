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
                                                    writes JSONL per line
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
│   ├── Fields/
│   │   └── BackgroundTaskField.php      # Self-contained FormField (start/stop)
│   ├── Models/
│   │   └── TaskRunnerConfig.php         # DataObject for the demo admin
│   ├── Services/
│   │   └── BackgroundTaskService.php    # Task lifecycle: start, stop, query
│   ├── Stores/
│   │   └── PsrCacheProgressStore.php    # FilesystemAdapter cache + /tmp stream files
│   └── Tasks/
│       ├── PingGoogleTask.php           # Demo task — pings google.com N times
│       └── ProgressTrackingOutput.php   # PolyOutput decorator for stream writing
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

A demo admin is available at `/admin/task-runner` via `TaskRunnerAdmin`.

## Installation

```bash
composer require lerni/cms-task
sake db:build --flush
```

## PHP CLI Binary

Tasks run as detached CLI processes via `sake`. By default, Silverstripe uses `PHP_BINARY` to locate the PHP executable. If your CLI PHP differs from the web server's PHP, you **must** set `SS_PHP_CLI_BINARY` in your `.env`:

```dotenv
SS_PHP_CLI_BINARY="/Applications/MAMP/bin/php/php8.4.2/bin/php"
```

**It is crucial that the CLI binary runs the same PHP version as your web server.** Mismatched versions can cause subtle errors — different extensions loaded, different behaviour, or outright failures.

To verify your configuration:

```bash
# Check which PHP the web server reports (visit /dev or phpinfo)
# Then compare with CLI:
/Applications/MAMP/bin/php/php8.4.2/bin/php -v
```

The version output should match what your web server uses.

## Requirements

- Silverstripe Framework ^6
- Silverstripe Admin ^3
