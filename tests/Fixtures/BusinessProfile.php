<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    protected $table = 'calendar_event_profile_business';

    protected $guarded = ['id'];

    public $timestamps = false;
}
