<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Benutzer für die Prüfungen des Pakets.
 *
 * Das Paket kennt kein Benutzermodell — es bekommt eines über
 * `calendar.user_model` genannt und weiss nur, dass es `name` hat. Genau
 * deshalb braucht es hier eines: Ohne steht in den Prüfungen „Class
 * App\Models\User not found", und das sagt etwas über die Prüfung, nicht über
 * das Paket.
 */
class DemoUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
