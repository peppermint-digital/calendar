<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Reminders\LeadPreference;
use Peppermint\Calendar\Reminders\ReminderLead;
use Peppermint\Reminders\Models\Reminder;

/*
| Die drei Ebenen des Vorlaufs — Natur der Sache, Vorgabe des Produkts,
| Entscheidung des Menschen.
|
| Dieselbe Form wie der `PreferenceResolver` in `peppermint/notifications`,
| und absichtlich dieselbe. Der eine Unterschied steht im Kopfkommentar der
| Migration: Dort heisst eine leere SPALTE „nicht entschieden", hier eine
| fehlende ZEILE — weil „aus" hier ein Wert sein muss und kein Fehlen.
*/

beforeEach(function () {
    config()->set('calendar.reminders.enabled', true);
    config()->set('calendar.reminders.lead_minutes', 15);
    config()->set('calendar.reminders.lead_choices', [5, 10, 15, 30, 60]);

    $this->vorlauf = app(ReminderLead::class);
});

it('nimmt ohne Zeile die Vorgabe des Produkts', function () {
    expect($this->vorlauf->minutenFuer(1))->toBe(15);
    expect($this->vorlauf->eigen(1))->toBeFalse();
});

it('folgt einer AENDERUNG der Vorgabe weiter, solange niemand entschieden hat', function () {
    // Der Grund, warum „nicht entschieden" keine Zeile hat und keinen Wert:
    // Ein eingefrorener Vorgabewert laesst sich spaeter nicht mehr anheben.
    expect($this->vorlauf->minutenFuer(1))->toBe(15);

    config()->set('calendar.reminders.lead_minutes', 30);
    $this->vorlauf->vergessen();

    expect($this->vorlauf->minutenFuer(1))->toBe(30);
});

it('nimmt die Entscheidung des Menschen vor die Vorgabe', function () {
    $this->vorlauf->setzen(1, 30);

    expect($this->vorlauf->minutenFuer(1))->toBe(30);
    expect($this->vorlauf->eigen(1))->toBeTrue();

    config()->set('calendar.reminders.lead_minutes', 5);
    $this->vorlauf->vergessen();

    expect($this->vorlauf->minutenFuer(1))->toBe(30);
});

it('haelt „keine" als ENTSCHEIDUNG, nicht als Fehlen', function () {
    $this->vorlauf->setzen(1, null);

    expect($this->vorlauf->minutenFuer(1))->toBeNull();
    expect($this->vorlauf->erinnert(1))->toBeFalse();
    // Die Zeile bleibt stehen — sonst griffe beim naechsten Lesen wieder die
    // Vorgabe, und das Abstellen haette nicht gehalten.
    expect($this->vorlauf->eigen(1))->toBeTrue();
    expect(LeadPreference::query()->where('user_id', 1)->exists())->toBeTrue();
});

it('loescht beim Zuruecksetzen die Zeile, statt den Vorgabewert hineinzuschreiben', function () {
    $this->vorlauf->setzen(1, 60);
    $this->vorlauf->zuruecksetzen(1);

    expect(LeadPreference::query()->where('user_id', 1)->exists())->toBeFalse();
    expect($this->vorlauf->eigen(1))->toBeFalse();
    expect($this->vorlauf->minutenFuer(1))->toBe(15);
});

it('laesst eine Vorgabe von null „nur wer ausdruecklich will" bedeuten', function () {
    config()->set('calendar.reminders.lead_minutes', null);
    $this->vorlauf->vergessen();

    expect($this->vorlauf->minutenFuer(1))->toBeNull();

    $this->vorlauf->setzen(1, 15);

    expect($this->vorlauf->minutenFuer(1))->toBe(15);
    expect($this->vorlauf->minutenFuer(2))->toBeNull();
});

it('gibt die Stufen sortiert und ohne Doppelte heraus', function () {
    config()->set('calendar.reminders.lead_choices', [30, 15, 30, 0, -5, 15]);
    $this->vorlauf->vergessen();

    expect($this->vorlauf->auswahl())->toBe([15, 30]);
});

it('liefert der Maske alles, was sie braucht', function () {
    $this->vorlauf->setzen(1, 30);

    expect($this->vorlauf->props(1))->toBe([
        'lead_minutes' => 30,
        'eigen' => true,
        'vorgabe' => 15,
        'choices' => [5, 10, 15, 30, 60],
    ]);
});

it('lehnt das Speichern laut ab, wenn die Tabelle fehlt', function () {
    // Ein eingestellter Wert, den niemand abholt, ist die stillste Art, eine
    // Einstellung zu verlieren.
    Schema::drop((new LeadPreference)->getTable());
    $this->vorlauf->vergessen();

    expect(fn () => $this->vorlauf->setzen(1, 30))
        ->toThrow(RuntimeException::class, 'calendar-reminder-leads');
});

it('zaehlt im Probelauf, was SOFORT herausginge — und laesst nichts stehen', function () {
    // Die Zahl, wegen der jemand einen Probelauf verlangt: „angelegt" allein
    // sagt sie nicht, weil die meisten Zeilen in der Zukunft liegen.
    $gleich = CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'In fuenf Minuten',
        'starts_at' => now()->addMinutes(5),
        'ends_at' => now()->addMinutes(35),
    ]);
    $gleich->attendees()->create(['user_id' => 2, 'email' => 'chris@example.test']);

    CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 3,
        'title' => 'Naechste Woche',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHour(),
    ]);

    // Ueber `Artisan::call` und nicht ueber `$this->artisan()`: Nur so laesst
    // sich die ganze Ausgabe lesen und gegen die ZAHLEN legen. Ein Probelauf,
    // dessen Zahl niemand prueft, ist nur ein Text.
    expect(Artisan::call('calendar:reconcile-reminders', ['--dry-run' => true]))->toBe(0);

    $ausgabe = Artisan::output();

    // Drei Zeilen entstehen, aber nur die zwei zum Termin in fuenf Minuten
    // sind sofort faellig (Vorlauf 15 Minuten — der Zeitpunkt liegt schon
    // hinter uns), und das sind zwei Menschen.
    expect($ausgabe)->toContain('PROBELAUF');
    expect(preg_match('/angelegt\D*3/', $ausgabe))->toBe(1);
    expect(preg_match('/davon sofort faellig\D*2/', $ausgabe))->toBe(1);
    expect(preg_match('/betroffene Menschen\D*2/', $ausgabe))->toBe(1);

    // Nichts bleibt stehen — das ist der ganze Punkt.
    expect(Reminder::count())->toBe(0);
});

it('misst im Probelauf auch dann, wenn der Schalter noch auf AUS steht', function () {
    // Der Fall JEDES Produkts, das noch nicht scharf geschaltet ist — und
    // genau der, fuer den die Zahl verlangt wird. Vorher kehrte `reconcile()`
    // bei `enabled = false` mit Nullen um: Der Probelauf meldete „0 sofort
    // faellig" und las sich wie eine Entwarnung, obwohl er nichts gemessen
    // hatte. Die Zahl gab es erst nach dem Einschalten, also nachdem das
    // Risiko eingegangen war, gegen das sie schuetzen soll.
    config()->set('calendar.reminders.enabled', false);

    $gleich = CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'In fuenf Minuten',
        'starts_at' => now()->addMinutes(5),
        'ends_at' => now()->addMinutes(35),
    ]);
    $gleich->attendees()->create(['user_id' => 2, 'email' => 'chris@example.test']);

    expect(Artisan::call('calendar:reconcile-reminders', ['--dry-run' => true]))->toBe(0);

    $ausgabe = Artisan::output();

    expect(preg_match('/angelegt\D*2/', $ausgabe))->toBe(1);
    expect(preg_match('/davon sofort faellig\D*2/', $ausgabe))->toBe(1);

    // Und die Ausgabe sagt, dass gemessen und nicht gearbeitet wurde — sonst
    // liest jemand die Zahl als „laeuft schon".
    expect($ausgabe)->toContain('AUS');

    // Der Schalter bleibt aus, und zwar ueber den Lauf hinaus: Umgelegt wird
    // nur im Speicher des Probelaufs.
    expect(config('calendar.reminders.enabled'))->toBeFalse();
    expect(Reminder::count())->toBe(0);
});

it('laesst einen ECHTEN Lauf bei ausgeschaltetem Schalter weiter in Ruhe', function () {
    // Die Gegenprobe zum Test darueber: Der Probelauf darf den Riegel oeffnen,
    // der Echtlauf nicht. Ohne diesen Test waere die Lockerung oben eine
    // Hintertuer, die niemand bemerkt.
    config()->set('calendar.reminders.enabled', false);

    CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'In fuenf Minuten',
        'starts_at' => now()->addMinutes(5),
        'ends_at' => now()->addMinutes(35),
    ]);

    expect(Artisan::call('calendar:reconcile-reminders'))->toBe(0);

    expect(Artisan::output())->toContain('abgeschaltet');
    expect(Reminder::count())->toBe(0);
});
