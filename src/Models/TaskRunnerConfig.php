<?php

namespace Kraftausdruck\Models;

use SilverStripe\Forms\TabSet;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Permission;
use Kraftausdruck\Fields\BackgroundTaskField;

/**
 * Minimal DataObject required by SingleRecordAdmin.
 *
 * Its only purpose is to provide getCMSFields() with
 * BackgroundTaskField instances for the POC task runner.
 */
class TaskRunnerConfig extends DataObject
{
    private static $table_name = 'TaskRunnerConfig';

    private static $db = [];

    public function getCMSFields()
    {
        $fields = FieldList::create(TabSet::create('Root'));

        $fields->addFieldToTab('Root.Main', BackgroundTaskField::create(
            'PingGoogle',
            'ping-google',
            ['count' => 30, 'delay' => 1],
            _t(self::class . '.PingGoogleTitle', 'Ping Google'),
        ));

        return $fields;
    }

    public function canView($member = null)
    {
        return Permission::check('CMS_ACCESS_TaskRunnerAdmin', 'any', $member);
    }

    public function canEdit($member = null)
    {
        return Permission::check('CMS_ACCESS_TaskRunnerAdmin', 'any', $member);
    }

    public function canCreate($member = null, $context = [])
    {
        return Permission::check('CMS_ACCESS_TaskRunnerAdmin', 'any', $member);
    }

    public function canDelete($member = null)
    {
        return false;
    }
}
