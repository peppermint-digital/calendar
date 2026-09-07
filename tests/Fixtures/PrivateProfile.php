<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class PrivateProfile extends Model
{
    protected $table = 'calendar_event_profile_private';

    protected $guarded = ['id'];

    public $timestamps = false;
}
