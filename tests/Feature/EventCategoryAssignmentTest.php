<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Categories\EventCategories;
use Peppermint\Calendar\Http\CalendarRoutes;
use Peppermint\Calendar\Models\CalendarCategory;
use Peppermint\Calendar\Models\CalendarEvent;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Die Kategorien eines einzelnen Termins setzen (#7846).
 *
 * Gemessen im Manager am 19.10.2026: 483 von 1211 Terminen sind Zeitblöcke an
 * einer Aufgabe — 40 Prozent des Kalenders, und keiner konnte eine Kategorie
 * tragen. Der naheliegende Weg wäre gewesen, sie aus der Aufgabe ABZULEITEN;
 * das wurde gemessen und verworfen, weil es nichts zum Ableiten gibt
 * (`tasks.kind` fast konstant, Projektkategorien leer — 11 von 307 Blöcken
 * hätten etwas bekommen). Also von Hand, und weil ein Block durch Ziehen
 * entsteht, über diesen eigenen kleinen Weg.
 */
beforeEach(function () {
    config()->set('calendar.categories.enabled', true);
    config()->set('calendar.categories.mode', 'closed');

    // Die `users`-Tabelle gehört der Anwendung, nicht dem Paket — es kennt
    // nur den Namen des Modells (`calendar.user_model`). Ohne sie steht in
    // der Prüfung „no such table: users", und das sagt etwas über die
    // Prüfung, nicht über das Paket. Genauso macht es `IcsFeedTest`.
    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            // `timestamps()`, weil `Illuminate\Foundation\Auth\User` sie
            // schreibt — ohne sie bricht das Anlegen mit „table users has no
            // column named updated_at", und das sieht aus wie ein Fehler im
            // Paket.
            $table->timestamps();
        });
    }

    Route::prefix('/')->group(fn () => CalendarRoutes::eventCategories());
});

/**
 * Eine Person, als die man sich anmelden kann.
 *
 * `DemoUser` reicht hier nicht: Es ist ein schlichtes `Model` und damit nicht
 * `Authenticatable` — `actingAs()` weist es ab. Das Paket kennt kein
 * Benutzermodell, es bekommt nur dessen Namen genannt; für einen angemeldeten
 * Aufruf braucht die Prüfung also eines, das sich anmelden lässt.
 */
class AngemeldeteDemoPerson extends AuthUser
{
    protected $table = 'users';

    protected $guarded = [];
}

function angemeldetePerson(array $werte): AngemeldeteDemoPerson
{
    return AngemeldeteDemoPerson::create($werte);
}

function zuordnungsKategorie(array $attributes = []): CalendarCategory
{
    return CalendarCategory::create(array_merge([
        'slug' => 'kundentermin',
        'label' => 'Kundentermin',
        'is_active' => true,
        'sort_order' => 0,
        'owner_id' => null,
    ], $attributes));
}

function zuordnungsTermin(int $ownerId, string $kind = 'plain_categorised'): CalendarEvent
{
    return CalendarEvent::create([
        'kind' => $kind,
        'title' => 'Zeitblock',
        'owner_id' => $ownerId,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);
}

it('setzt die Kategorien eines eigenen Termins', function () {
    zuordnungsKategorie();
    zuordnungsKategorie(['slug' => 'vertrieb', 'label' => 'Vertrieb', 'sort_order' => 10]);

    $person = angemeldetePerson(['name' => 'Volker', 'email' => 'volker@example.test']);
    $termin = zuordnungsTermin((int) $person->id);

    $this->actingAs($person)
        ->patchJson("/calendar/{$termin->id}/categories", ['categories' => ['vertrieb', 'kundentermin']])
        ->assertOk()
        ->assertJson(['categories' => ['kundentermin', 'vertrieb']]);

    expect(app(EventCategories::class)->slugsOf($termin->refresh()))->toBe(['kundentermin', 'vertrieb']);
});

/** Eine leere Liste ist eine Aussage: „keine Kategorie mehr". */
it('nimmt alle Kategorien wieder weg', function () {
    zuordnungsKategorie();
    $person = angemeldetePerson(['name' => 'Volker', 'email' => 'volker@example.test']);
    $termin = zuordnungsTermin((int) $person->id);
    app(EventCategories::class)->set($termin, ['kundentermin'], (int) $person->id);

    $this->actingAs($person)
        ->patchJson("/calendar/{$termin->id}/categories", ['categories' => []])
        ->assertOk();

    expect(app(EventCategories::class)->slugsOf($termin->refresh()))->toBe([]);
});

/**
 * Sehen heisst nicht aendern: Sonst traegt die Auswertung einer Person die
 * Etiketten einer anderen.
 */
it('laesst einen TEILNEHMER den Termin nicht einordnen', function () {
    zuordnungsKategorie();

    $eigner = angemeldetePerson(['name' => 'Volker', 'email' => 'volker@example.test']);
    $andere = angemeldetePerson(['name' => 'Andere', 'email' => 'andere@example.test']);
    $termin = zuordnungsTermin((int) $eigner->id);

    // Als Teilnehmer, denn nur so SIEHT die andere Person den Termin
    // überhaupt. Ein fremder, unsichtbarer Termin ergibt 404 — das ist der
    // Test darunter. Hier geht es um den Unterschied zwischen sehen und
    // ändern, und den gibt es nur in diesem Fall.
    $termin->attendees()->create([
        'user_id' => $andere->id,
        'email' => $andere->email,
        
    ]);

    $this->actingAs($andere)
        ->patchJson("/calendar/{$termin->id}/categories", ['categories' => ['kundentermin']])
        ->assertStatus(403);

    expect(app(EventCategories::class)->slugsOf($termin->refresh()))->toBe([]);
});

it('findet einen fremden, unsichtbaren Termin gar nicht', function () {
    zuordnungsKategorie();

    $eigner = angemeldetePerson(['name' => 'Volker', 'email' => 'volker@example.test']);
    $andere = angemeldetePerson(['name' => 'Andere', 'email' => 'andere@example.test']);
    $termin = zuordnungsTermin((int) $eigner->id);
    $termin->forceFill(['visibility' => 'private'])->save();

    $this->actingAs($andere)
        ->patchJson("/calendar/{$termin->id}/categories", ['categories' => ['kundentermin']])
        ->assertStatus(404);
});

/**
 * 422 und nicht 403: Es fehlt kein Recht, die Angabe passt nicht. Geprüft wird
 * das in `EventCategories::set()` — dort, wo es für jeden Aufrufer gilt.
 */
it('weist eine Kategorie ab, die diese Terminart nicht anbietet', function () {
    zuordnungsKategorie(['slug' => 'sport', 'label' => 'Sport', 'kind' => 'private']);

    $person = angemeldetePerson(['name' => 'Volker', 'email' => 'volker@example.test']);
    $termin = zuordnungsTermin((int) $person->id);

    $this->actingAs($person)
        ->patchJson("/calendar/{$termin->id}/categories", ['categories' => ['sport']])
        ->assertStatus(422);
});

it('weist eine Terminart ab, die keine Kategorien traegt', function () {
    zuordnungsKategorie();

    $person = angemeldetePerson(['name' => 'Volker', 'email' => 'volker@example.test']);
    $termin = zuordnungsTermin((int) $person->id, 'private');

    $this->actingAs($person)
        ->patchJson("/calendar/{$termin->id}/categories", ['categories' => ['kundentermin']])
        ->assertStatus(422);
});

it('gibt es nicht, wenn Kategorien in diesem System aus sind', function () {
    config()->set('calendar.categories.enabled', false);

    $person = angemeldetePerson(['name' => 'Volker', 'email' => 'volker@example.test']);
    $termin = zuordnungsTermin((int) $person->id);

    $this->actingAs($person)
        ->patchJson("/calendar/{$termin->id}/categories", ['categories' => []])
        ->assertStatus(404);
});
