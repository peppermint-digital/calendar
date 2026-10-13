<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Peppermint\Calendar\Models\PlannedBlockProfile;

class TaskBlockProfile extends PlannedBlockProfile
{
    protected $table = 'calendar_event_profile_task_block';

    protected string $subjectModel = DemoTask::class;

    protected string $subjectKey = 'demo_task_id';
}
