<?php

namespace Kraftausdruck\Admin;

use SilverStripe\Admin\SingleRecordAdmin;
use Kraftausdruck\Models\TaskRunnerConfig;

/**
 * POC admin panel for running background tasks from the CMS.
 *
 * Uses SingleRecordAdmin with a minimal DataObject whose getCMSFields()
 * provides BackgroundTaskField instances for each registered task.
 */
class TaskRunnerAdmin extends SingleRecordAdmin
{
    private static $url_segment = 'task-runner';

    private static $menu_title = 'Task Runner';

    private static $menu_icon_class = 'font-icon-rocket';

    private static $model_class = TaskRunnerConfig::class;

    private static $required_permission_codes = 'CMS_ACCESS_TaskRunnerAdmin';

    private static $extra_requirements_javascript = [
        'lerni/cms-task: client/dist/js/background-task-field.js',
    ];

    private static $extra_requirements_css = [
        'lerni/cms-task: client/dist/css/background-task-field.css',
    ];
}
