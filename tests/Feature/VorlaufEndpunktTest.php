<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;
use Peppermint\Calendar\Http\CalendarRoutes;
use Peppermint\Calendar\Reminders\LeadPreference;
use Peppermint\Calendar\Reminders\ReminderLead;

/*
| Der Endpunkt hinter „keine / 15 / 30 Minuten vorher".
|
| Geprueft wird die Verdrahtung, nicht die Regel — die steht in
| `VorlaufEinstellungTest`. Hier: dass die Routen gelegt werden koennen, dass
| eine Stufe ausserhalb der Auswahl abgelehnt wird, und dass die drei Faelle
| wirklich drei sind.
*/

beforeEach(function () {
    config()->set('calendar.reminders.lead_minutes', 15);
    config()->set('calendar.reminders.lead_choices', [15, 30]);

    // Ohne `web`: Diese Endpunkte sind JSON. Die Anmeldeschranke legt das
    // Produkt, nicht das Paket — hier steht sie als `actingAs`.
    Route::prefix('settings')->group(fn () => CalendarRoutes::reminderLead());

    $this->person = new GenericUser(['id' => 7]);
});

it('nennt, was gilt, was die Vorgabe ist und was zur Wahl steht', function () {
    $this->actingAs($this->person)
        ->getJson('settings/calendar/reminder-lead')
        ->assertOk()
        ->assertExactJson([
            'lead_minutes' => 15,
            'eigen' => false,
            'vorgabe' => 15,
            'choices' => [15, 30],
        ]);
});

it('nimmt eine Stufe aus der Auswahl an', function () {
    $this->actingAs($this->person)
        ->putJson('settings/calendar/reminder-lead', ['lead_minutes' => 30])
        ->assertOk()
        ->assertJson(['ok' => true, 'lead_minutes' => 30, 'eigen' => true]);

    expect(app(ReminderLead::class)->minutenFuer(7))->toBe(30);
});

it('nimmt „keine" als Entscheidung an', function () {
    $this->actingAs($this->person)
        ->putJson('settings/calendar/reminder-lead', ['lead_minutes' => null])
        ->assertOk()
        ->assertJson(['lead_minutes' => null, 'eigen' => true]);

    expect(LeadPreference::query()->where('user_id', 7)->exists())->toBeTrue();
});

it('gibt die Entscheidung beim Zuruecksetzen wieder auf', function () {
    app(ReminderLead::class)->setzen(7, 30);

    $this->actingAs($this->person)
        ->putJson('settings/calendar/reminder-lead', ['reset' => true])
        ->assertOk()
        ->assertJson(['lead_minutes' => 15, 'eigen' => false]);

    expect(LeadPreference::query()->where('user_id', 7)->exists())->toBeFalse();
});

it('lehnt eine Stufe ab, die keine Maske anbietet', function () {
    // Sonst steht morgen eine 7 in der Spalte, und niemand weiss, wie sie
    // hineingekommen ist.
    $this->actingAs($this->person)
        ->putJson('settings/calendar/reminder-lead', ['lead_minutes' => 7])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lead_minutes');
});

it('trifft nur die angemeldete Person', function () {
    app(ReminderLead::class)->setzen(99, 30);

    $this->actingAs($this->person)
        ->putJson('settings/calendar/reminder-lead', ['lead_minutes' => 15])
        ->assertOk();

    $vorlauf = app(ReminderLead::class);

    expect($vorlauf->minutenFuer(7))->toBe(15);
    expect($vorlauf->minutenFuer(99))->toBe(30);
});
