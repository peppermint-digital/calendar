<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class DemoTask extends Model
{
    protected $table = 'demo_tasks';

    protected $guarded = [];

    public $timestamps = false;
}
