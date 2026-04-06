# lerni/cms-task

STATUS: **POC**, basically working implementation - many ideas still floating.

Background BuildTask execution with real-time progress for Silverstripe CMS.

Tasks run as **detached CLI processes** (via `sake`), so the browser never blocks. Progress is streamed in real-time through JSONL files read by an SSE endpoint. A PSR-16 cache stores task metadata for status recovery.

## How it works

```
Browser (CMS)                    Server
─────────────────────────────────────────────
BackgroundTaskField  ──POST──▶  BackgroundTaskService
  "start task"                    ├─ initTask() in PsrCacheProgressStore
                                  └─ spawnExecutor() ──▶ detached CLI
                                                          │
EventSource  ◀──SSE──  TaskStreamController               ▼
  (tail-f read)          reads .stream file   ◀── BackgroundTaskExecutor
                                                    writes JSONL per line
```

## File overview

```
cms-task/
├── _config/backgroundtasks.yml          # Injector bindings + Director routes
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
│       ├── BackgroundTaskExecutor.php   # Runs target task as subprocess, writes JSONL
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

Shared service for the task lifecycle. Resolves task command names to classes, spawns detached executors via `sake tasks:background-executor`, and manages the progress store.

### PsrCacheProgressStore

Uses Symfony's `FilesystemAdapter` directly (bypasses Silverstripe's `CacheFactory` to ensure cache visibility across CLI and web processes). Stream files live at `/tmp/ss_background_tasks/`. The cache holds task metadata; the stream file is the real-time delivery channel.

### BackgroundTaskExecutor

A BuildTask that runs another BuildTask as a subprocess via `proc_open`. Captures stdout line-by-line, writes JSONL to the stream file, and extracts progress from output patterns like `Processing step X/Y`.

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

A demo admin is available at `/admin/task-runner` via `TaskRunnerAdmin`.

## Installation

```bash
composer require lerni/cms-task
sake db:build --flush
```

## Requirements

- Silverstripe CMS ^6.0
- PHP 8.1+
