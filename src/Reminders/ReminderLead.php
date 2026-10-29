<?php

namespace Peppermint\Calendar\Reminders;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Die eine Stelle, an der „wie lange vor dem Termin will dieser Mensch es
 * wissen?" beantwortet wird.
 *
 * Drei Ebenen, von unten nach oben — dieselbe Form wie der
 * `PreferenceResolver` in `peppermint/notifications`, und absichtlich dieselbe:
 *
 * 1. **Die Natur der Sache** — eine Vorwarnung vor dem Start. Eine Erinnerung
 *    ZUM Start ist eine Erinnerung an etwas, zu dem man schon zu spaet ist.
 *    Das Paket liefert 15 Minuten.
 * 2. **Die Vorgabe des Produkts** — `calendar.reminders.lead_minutes`. Hier
 *    steht, was sinnvoll IST.
 * 3. **Die Entscheidung des Menschen** — eine Zeile in der Tabelle. Hier
 *    steht, was jemand WILL, „keine" eingeschlossen.
 *
 * Die unteren Ebenen sind keine Voreinstellung, die beim ersten Speichern
 * einfriert: Wer nichts entschieden hat, folgt der Vorgabe weiter, auch wenn
 * sie sich aendert. Deshalb loescht das Zuruecksetzen die Zeile.
 *
 * ## Warum diese Einstellung ins KALENDER-Paket gehoert
 *
 * Gefragt wurde am 29.10.2026, ob sie nicht nach `peppermint/notifications`
 * gehoert, wo die uebrigen Benachrichtigungs-Einstellungen liegen. Nein, und
 * zwar aus drei Gruenden:
 *
 * - **Das Meldungs-Paket weiss nichts von Startzeiten.** Es kennt Arten und
 *   Wege. „15 Minuten vor dem Anfang" setzt einen Anfang voraus, und einen
 *   Ganztagsfall, bei dem die Rechnung nicht gilt. Das ist Kalenderwissen.
 * - **Es waere eine neue Spalte in der geteilten Mitte** (Paketvertrag Regel
 *   5) und wuerde in jedes Produkt mitwandern, das Meldungen hat und keinen
 *   Kalender.
 * - **Das Erinnerungs-Paket ist es auch nicht.** Entscheidung #4717:
 *   `peppermint/reminders` traegt den Speicher und die Frage, nicht die Regel.
 *   Der Vorlauf IST die Regel, die den Zeitpunkt erzeugt — und sie lebt beim
 *   Anlass, also hier.
 *
 * Was NICHT hierher gehoert und auch nicht hier liegt: auf welchem WEG die
 * Erinnerung ankommt. Glocke, Push und Mail entscheidet weiter das
 * Meldungs-Paket je Person und je Art.
 */
class ReminderLead
{
    /**
     * Alle Zeilen, einmal gelesen.
     *
     * Der Abgleich fragt fuer jeden Teilnehmer jedes Termins — ohne das waere
     * das eine Abfrage je Person je Termin. `null` heisst „noch nicht
     * gelesen" und ist deshalb nicht dasselbe wie eine leere Sammlung.
     *
     * @var Collection<array-key, LeadPreference>|null
     */
    private ?Collection $gelesen = null;

    /**
     * Gibt es die Tabelle? Einmal je Aufruf beantwortet.
     *
     * `null` heisst „noch nicht nachgesehen" — nicht dasselbe wie `false`.
     */
    private ?bool $tabelleGeprueft = null;

    /**
     * Der Vorlauf in Minuten, oder null fuer „keine Erinnerung".
     */
    public function minutenFuer(Model|Authenticatable|int|string $person): ?int
    {
        $zeile = $this->zeile($person);

        return $zeile === null ? $this->vorgabe() : $zeile->lead_minutes;
    }

    /** Bekommt dieser Mensch ueberhaupt Termin-Erinnerungen? */
    public function erinnert(Model|Authenticatable|int|string $person): bool
    {
        return $this->minutenFuer($person) !== null;
    }

    /**
     * Die Vorgabe des Produkts — ohne die Person.
     *
     * `null` in der Konfiguration ist zulaessig und heisst „niemand bekommt
     * eine, der nicht ausdruecklich eine will". Ein Produkt kann die Funktion
     * damit anbieten, ohne sie allen aufzudraengen.
     */
    public function vorgabe(): ?int
    {
        $wert = config('calendar.reminders.lead_minutes', 15);

        return $wert === null ? null : max(0, (int) $wert);
    }

    /** Hat die Person das selbst entschieden? */
    public function eigen(Model|Authenticatable|int|string $person): bool
    {
        return $this->zeile($person) !== null;
    }

    /**
     * Die Stufen, die ein Produkt anbietet — in Minuten.
     *
     * Die ZAHLEN stehen in der Konfiguration, weil sie ueberall dieselben sind.
     * Die WORTE nicht: Ein Paket, das Beschriftungen mitbringt, bringt eine
     * Sprache mit, und das Produkt hat schon eine. Dieselbe Trennung wie beim
     * Schlummern in `peppermint/reminders`.
     *
     * @return array<int, int>
     */
    public function auswahl(): array
    {
        $stufen = array_map('intval', (array) config('calendar.reminders.lead_choices', [15, 30]));
        $stufen = array_values(array_unique(array_filter($stufen, fn (int $m): bool => $m > 0)));

        sort($stufen);

        return $stufen;
    }

    /**
     * Alles, was eine Einstellungsmaske braucht.
     *
     * Die Stufen kommen MIT. Stuenden sie im Frontend, waeren sie beim
     * naechsten Mal nur dort ergaenzt — und die Maske boete einen Vorlauf an,
     * den der Server nicht annimmt.
     *
     * @return array{lead_minutes: int|null, eigen: bool, vorgabe: int|null, choices: array<int, int>}
     */
    public function props(Model|Authenticatable|int|string $person): array
    {
        return [
            'lead_minutes' => $this->minutenFuer($person),
            'eigen' => $this->eigen($person),
            'vorgabe' => $this->vorgabe(),
            'choices' => $this->auswahl(),
        ];
    }

    /**
     * Eine Entscheidung festhalten. `null` heisst „keine Erinnerung".
     *
     * Nicht dasselbe wie {@see zuruecksetzen()}: Wer „keine" waehlt, hat
     * entschieden und soll von einer spaeteren Aenderung der Vorgabe nicht
     * wieder eingeschaltet werden.
     */
    public function setzen(Model|Authenticatable|int|string $person, ?int $minuten): void
    {
        if (! $this->tabelleDa()) {
            // Lieber laut als ein gespeicherter Wert, den niemand abholt.
            throw new \RuntimeException(sprintf(
                'Die Tabelle [%s] fehlt. '
                .'php artisan vendor:publish --tag=calendar-reminder-leads && php artisan migrate',
                (new LeadPreference)->getTable(),
            ));
        }

        $id = $this->schluessel($person);

        LeadPreference::query()->updateOrCreate(
            ['user_id' => $id],
            ['lead_minutes' => $minuten === null ? null : max(0, $minuten)],
        );

        $this->vergessen();
    }

    /** Die Entscheidung aufgeben — danach gilt wieder die Vorgabe. */
    public function zuruecksetzen(Model|Authenticatable|int|string $person): void
    {
        if (! $this->tabelleDa()) {
            return;
        }

        LeadPreference::query()->where('user_id', $this->schluessel($person))->delete();

        $this->vergessen();
    }

    /** Den Zwischenspeicher leeren — nach jeder Aenderung. */
    public function vergessen(): void
    {
        $this->gelesen = null;
        $this->tabelleGeprueft = null;
    }

    private function zeile(Model|Authenticatable|int|string $person): ?LeadPreference
    {
        return $this->alle()->get($this->schluessel($person));
    }

    /**
     * @return Collection<array-key, LeadPreference>
     */
    private function alle(): Collection
    {
        if ($this->gelesen !== null) {
            return $this->gelesen;
        }

        if (! $this->tabelleDa()) {
            return $this->gelesen = collect();
        }

        return $this->gelesen = LeadPreference::query()->get()->keyBy(
            fn (LeadPreference $zeile) => (string) $zeile->user_id
        );
    }

    /**
     * Steht die Tabelle schon?
     *
     * ## Warum das kein Misstrauen gegen die eigene Migration ist
     *
     * Zwischen „neuer Code ist scharf" und „Migration ist gelaufen" liegt
     * immer ein Fenster — und diese Migration wird ueberdies erst
     * veroeffentlicht, also kann sie in einem Produkt ganz fehlen. Ohne diese
     * Pruefung wuerde der Abgleich dort abbrechen, und niemand bekaeme eine
     * Erinnerung. Mit ihr gilt fuer alle die Vorgabe des Produkts: weniger,
     * als jemand eingestellt hat, aber nicht nichts.
     *
     * Die Antwort wird gemerkt: `hasTable` fragt den Katalog, und das je
     * Person zu tun waere teurer als das, was es absichert.
     */
    public function tabelleDa(): bool
    {
        if ($this->tabelleGeprueft !== null) {
            return $this->tabelleGeprueft;
        }

        try {
            $da = Schema::hasTable((new LeadPreference)->getTable());
        } catch (\Throwable) {
            // Keine Datenbank erreichbar: Dann ist „keine Einstellungen" die
            // richtige Annahme, nicht ein zweiter Fehler obendrauf.
            $da = false;
        }

        if (! $da) {
            Log::warning('Die Tabelle fuer den Termin-Vorlauf je Person fehlt — es gilt die Vorgabe des Produkts.', [
                'tabelle' => (new LeadPreference)->getTable(),
            ]);
        }

        return $this->tabelleGeprueft = $da;
    }

    /**
     * Die Kennung der Person — egal, in welcher Gestalt sie hereinkommt.
     *
     * `Authenticatable` ist mit Absicht dabei und nicht nur `Model`: Ein
     * Produkt kann eine Wache fuehren, deren Nutzer kein Eloquent-Modell ist,
     * und eine Paketpruefung tut es ohnehin (`GenericUser`). Ein zu enger
     * Typ waere dort ein Abbruch in der Einstellungsmaske — fuer einen Wert,
     * den beide Gestalten gleich gut beantworten koennen.
     */
    private function schluessel(Model|Authenticatable|int|string $person): string
    {
        if ($person instanceof Model) {
            return (string) $person->getKey();
        }

        if ($person instanceof Authenticatable) {
            return (string) $person->getAuthIdentifier();
        }

        return (string) $person;
    }
}
