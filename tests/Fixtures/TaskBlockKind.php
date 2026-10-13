<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Peppermint\Calendar\Kinds\PlannedBlockKind;

/**
 * Steht fuer den Zeitblock, den heute vier Systeme je selbst gebaut haben.
 */
class TaskBlockKind extends PlannedBlockKind
{
    public function key(): string
    {
        return 'task_block';
    }

    public function label(): string
    {
        return 'Zeitblock';
    }

    public function subjectModel(): string
    {
        return DemoTask::class;
    }

    public function profileModel(): ?string
    {
        return TaskBlockProfile::class;
    }
}
