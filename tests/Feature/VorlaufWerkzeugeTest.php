<?php

use Illuminate\Auth\GenericUser;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Peppermint\Calendar\Mcp\ListEventReminderLeadTool;
use Peppermint\Calendar\Mcp\SetEventReminderLeadTool;
use Peppermint\Calendar\Reminders\LeadPreference;
use Peppermint\Calendar\Reminders\ReminderLead;

/*
| Die Pflegemaske fuer den Vorlauf steht an EINER Stelle — im AI Brain, neben
| der Benachrichtigungs-Matrix (#520, fuer den Vorlauf bestaetigt am
| 29.10.2026). Damit sie auch den Vorlauf der anderen Systeme stellen kann,
| braucht sie zwei Werkzeuge je Produkt: eines, das Stand und Stufen
| herausgibt, und eines, das eine Entscheidung festhaelt.
|
| Geprueft wird der Vertrag nach aussen, nicht die Regel — die steht in
| `VorlaufEinstellungTest`.
*/

beforeEach(function (): void {
    config()->set('calendar.reminders.enabled', true);
    config()->set('calendar.reminders.lead_minutes', 15);
    config()->set('calendar.reminders.lead_choices', [15, 30]);

    $this->ich = new GenericUser(['id' => 7]);
});

function vorlaufLesen(): array
{
    return vorlaufNutzlast(
        app(ListEventReminderLeadTool::class)->handle(new Request([]), app(ReminderLead::class))
    );
}

function vorlaufRohSetzen(array $argumente): Response
{
    return app(SetEventReminderLeadTool::class)->handle(new Request($argumente), app(ReminderLead::class));
}

function vorlaufSetzen(array $argumente): array
{
    return vorlaufNutzlast(vorlaufRohSetzen($argumente));
}

/**
 * Die Nutzlast herausschaelen.
 *
 * Ueber den Text und nicht ueber eine Methodenkette: Der Weg dorthin hat sich
 * zwischen den SDK-Fassungen schon geaendert, und eine festgeschriebene Kette
 * bricht beim naechsten Update.
 */
function vorlaufNutzlast(Response $antwort): array
{
    $daten = json_decode((string) $antwort->content(), true);

    return is_array($daten) ? $daten : [];
}

/* ------------------------------------------------------------------ lesen */

it('sagt ohne handelnde Person ausdruecklich, dass es keine gab', function (): void {
    // `lead_minutes: null` heisst sonst „keine Erinnerung", und das ist etwas
    // ganz anderes als „ich konnte nicht nachsehen". Die Zweideutigkeit
    // gehoert aufgeloest, nicht weitergegeben.
    $antwort = vorlaufLesen();

    expect($antwort['person'])->toBeFalse();
    expect($antwort['lead_minutes'])->toBeNull();
    // Die Stufen und der Schalter sind trotzdem eine ehrliche Auskunft ueber
    // dieses System.
    expect($antwort['choices'])->toBe([15, 30]);
    expect($antwort['enabled'])->toBeTrue();
});

it('nennt, ob dieses Produkt Termin-Erinnerungen ueberhaupt schickt', function (): void {
    // Eine Maske, die einen Vorlauf fuer ein System anbietet, das nichts
    // schickt, verspricht etwas, das nicht passiert.
    config()->set('calendar.reminders.enabled', false);
    $this->actingAs($this->ich);

    expect(vorlaufLesen()['enabled'])->toBeFalse();
});

it('unterscheidet eine Entscheidung von der Vorgabe', function (): void {
    // Der ganze Witz: Ohne dieses Feld kann die Maske „folgt der Vorgabe"
    // nicht von „steht auf 15" unterscheiden.
    $this->actingAs($this->ich);

    expect(vorlaufLesen())
        ->lead_minutes->toBe(15)
        ->eigen->toBeFalse()
        ->vorgabe->toBe(15);

    app(ReminderLead::class)->setzen(7, 15);

    expect(vorlaufLesen())
        ->lead_minutes->toBe(15)
        ->eigen->toBeTrue();
});

/* --------------------------------------------------------------- schreiben */

it('lehnt ohne handelnde Person ab, statt still nichts zu tun', function (): void {
    // Ein stilles „nichts getan" waere die schlimmere Variante: Die Maske
    // drueben zeigte einen gesetzten Wert, und erinnert wuerde nach dem Alten.
    $antwort = vorlaufRohSetzen(['lead' => '30']);

    expect((string) $antwort->content())->toContain('No acting person');
    expect(LeadPreference::count())->toBe(0);
});

it('nimmt eine Stufe aus der Auswahl an und gibt den neuen Stand zurueck', function (): void {
    $this->actingAs($this->ich);

    expect(vorlaufSetzen(['lead' => '30']))
        ->lead_minutes->toBe(30)
        ->eigen->toBeTrue()
        ->vorgabe->toBe(15);

    expect(app(ReminderLead::class)->minutenFuer(7))->toBe(30);
});

it('haelt „none" als Entscheidung — nicht als Vorgabe', function (): void {
    $this->actingAs($this->ich);

    expect(vorlaufSetzen(['lead' => 'none']))
        ->lead_minutes->toBeNull()
        ->eigen->toBeTrue();

    // Die Zeile bleibt stehen. Sonst griffe beim naechsten Lesen wieder die
    // Vorgabe, und das Abstellen haette nicht gehalten.
    expect(LeadPreference::query()->where('user_id', 7)->exists())->toBeTrue();
});

it('gibt mit „default" die Entscheidung auf', function (): void {
    $this->actingAs($this->ich);
    app(ReminderLead::class)->setzen(7, 30);

    expect(vorlaufSetzen(['lead' => 'default']))
        ->lead_minutes->toBe(15)
        ->eigen->toBeFalse();

    expect(LeadPreference::query()->where('user_id', 7)->exists())->toBeFalse();
});

it('folgt nach „default" einer spaeteren Aenderung der Vorgabe weiter', function (): void {
    // Genau deshalb ist „default" nicht dasselbe wie „auf 15 setzen".
    $this->actingAs($this->ich);
    app(ReminderLead::class)->setzen(7, 30);
    vorlaufSetzen(['lead' => 'default']);

    config()->set('calendar.reminders.lead_minutes', 5);

    expect(vorlaufLesen()['lead_minutes'])->toBe(5);
});

it('lehnt eine Stufe ab, die dieses Produkt nicht anbietet — mit der Liste dabei', function (): void {
    $this->actingAs($this->ich);

    $text = (string) vorlaufRohSetzen(['lead' => '7'])->content();

    expect($text)->toContain('Unknown lead "7"');
    expect($text)->toContain('15, 30');
    expect(LeadPreference::count())->toBe(0);
});

it('lehnt auch ab, was nach einer Zahl aussieht und keine ist', function (): void {
    $this->actingAs($this->ich);

    expect((string) vorlaufRohSetzen(['lead' => '30 Minuten'])->content())->toContain('Unknown lead');
    expect((string) vorlaufRohSetzen(['lead' => '-15'])->content())->toContain('Unknown lead');
    expect(LeadPreference::count())->toBe(0);
});

it('sagt was fehlt, wenn gar kein Wert kommt', function (): void {
    $this->actingAs($this->ich);

    expect((string) vorlaufRohSetzen([])->content())->toContain('Missing "lead"');
});

it('gibt die Absage des Speichers weiter, statt sie zu verschlucken', function (): void {
    // Haeufigster Fall: Die Migration wird nur VEROEFFENTLICHT, ein Produkt
    // kann sie also noch nicht haben. Lieber eine Absage mit dem Befehl darin
    // als ein Wert, den niemand abholt.
    Illuminate\Support\Facades\Schema::drop((new LeadPreference)->getTable());
    $this->actingAs($this->ich);

    expect((string) vorlaufRohSetzen(['lead' => '30'])->content())
        ->toContain('calendar-reminder-leads');
});
