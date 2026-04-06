<?php

namespace Kraftausdruck\Fields;

use SilverStripe\Forms\FormField;
use SilverStripe\Control\Director;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Permission;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\SecurityToken;
use Kraftausdruck\Services\BackgroundTaskService;

/**
 * A self-contained FormField for starting, monitoring, and stopping
 * background BuildTasks from any CMS form.
 *
 * HTTP actions on this field:
 *   POST start  — spawns a detached CLI task, returns {taskId} JSON
 *   POST stop   — kills a running task by taskId
 *
 * Real-time progress comes via SSE from TaskStreamController at
 * /task-stream/{taskId} (separate lightweight controller).
 *
 * Usage:
 *   BackgroundTaskField::create('PingGoogle', 'ping-google', ['count' => 10])
 */
class BackgroundTaskField extends FormField
{
    private static $allowed_actions = [
        'handleStart',
        'handleStop',
    ];

    private static $url_handlers = [
        'start' => 'handleStart',
        'stop' => 'handleStop',
    ];

    protected string $taskCommandName;

    /** @var array<string, mixed> */
    protected array $taskOptions;

    protected ?string $scopeKey = null;

    protected $schemaDataType = self::SCHEMA_DATA_TYPE_CUSTOM;

    /**
     * @param string $name Field name (unique per form)
     * @param string $taskCommandName BuildTask command name (e.g. 'ping-google')
     * @param array<string, mixed> $taskOptions Default CLI options for this task
     * @param string|null $title Human-readable label
     */
    public function __construct(
        string $name,
        string $taskCommandName,
        array $taskOptions = [],
        ?string $title = null,
    ) {
        $this->taskCommandName = $taskCommandName;
        $this->taskOptions = $taskOptions;

        parent::__construct($name, $title ?: $taskCommandName);
    }

    public function Field($properties = [])
    {
        Requirements::javascript('lerni/cms-task: client/dist/js/background-task-field.js');
        Requirements::css('lerni/cms-task: client/dist/css/background-task-field.css');

        return parent::Field($properties);
    }

    public function getTaskCommandName(): string
    {
        return $this->taskCommandName;
    }

    public function getStartLink(): string
    {
        return $this->Link('start');
    }

    public function getStopLink(): string
    {
        return $this->Link('stop');
    }

    public function getStreamBaseURL(): string
    {
        return Controller::join_links(Director::baseURL(), 'task-stream') . '/';
    }

    public function getSecurityID(): string
    {
        return SecurityToken::inst()->getValue();
    }

    public function setScopeKey(?string $scopeKey): self
    {
        $this->scopeKey = $scopeKey;

        return $this;
    }

    public function getScopeKey(): ?string
    {
        return $this->scopeKey;
    }

    /**
     * Find an active task matching this field's command (and scope key).
     * Returns the task ID if found, null otherwise.
     */
    public function getActiveTaskId(): ?string
    {
        /** @var BackgroundTaskService $service */
        $service = Injector::inst()->get(BackgroundTaskService::class);
        $meta = $service->findActiveTask($this->taskCommandName, $this->scopeKey);

        return $meta['task_id'] ?? null;
    }

    /**
     * Start a background task. Returns JSON.
     */
    public function handleStart(HTTPRequest $request): HTTPResponse
    {
        $response = HTTPResponse::create();
        $response->addHeader('Content-Type', 'application/json');

        if (!Permission::check('CMS_ACCESS')) {
            $response->setStatusCode(403);
            $response->setBody(json_encode(['success' => false, 'message' => 'Not authorised']));

            return $response;
        }

        if (!SecurityToken::inst()->checkRequest($request)) {
            $response->setStatusCode(400);
            $response->setBody(json_encode(['success' => false, 'message' => 'Invalid security token']));

            return $response;
        }

        try {
            /** @var BackgroundTaskService $service */
            $service = Injector::inst()->get(BackgroundTaskService::class);

            // Return existing task if one is already active for this command + scope
            $existing = $service->findActiveTask($this->taskCommandName, $this->scopeKey);
            if ($existing) {
                $response->setBody(json_encode([
                    'success' => true,
                    'taskId' => $existing['task_id'],
                ]));

                return $response;
            }

            $memberId = Security::getCurrentUser()?->ID;
            $meta = $service->startBackgroundTask(
                $this->taskCommandName,
                $this->taskOptions,
                null,
                $memberId,
                $this->scopeKey,
            );

            $response->setBody(json_encode([
                'success' => true,
                'taskId' => $meta['task_id'],
            ]));
        } catch (\Exception $e) {
            $response->setStatusCode(500);
            $response->setBody(json_encode(['success' => false, 'message' => $e->getMessage()]));
        }

        return $response;
    }

    /**
     * Stop a running task. Returns JSON.
     */
    public function handleStop(HTTPRequest $request): HTTPResponse
    {
        $response = HTTPResponse::create();
        $response->addHeader('Content-Type', 'application/json');

        if (!Permission::check('CMS_ACCESS')) {
            $response->setStatusCode(403);
            $response->setBody(json_encode(['success' => false, 'message' => 'Not authorised']));

            return $response;
        }

        if (!SecurityToken::inst()->checkRequest($request)) {
            $response->setStatusCode(400);
            $response->setBody(json_encode(['success' => false, 'message' => 'Invalid security token']));

            return $response;
        }

        $taskId = $request->postVar('taskId');
        if (!$taskId) {
            $response->setStatusCode(400);
            $response->setBody(json_encode(['success' => false, 'message' => 'taskId required']));

            return $response;
        }

        /** @var BackgroundTaskService $service */
        $service = Injector::inst()->get(BackgroundTaskService::class);
        $stopped = $service->stopBackgroundTask($taskId);

        $response->setBody(json_encode([
            'success' => $stopped,
            'message' => $stopped ? 'Task stopped' : 'Task not found or already finished',
        ]));

        return $response;
    }
}
