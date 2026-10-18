<?php

namespace Peppermint\Calendar\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Etikett innerhalb einer Terminart.
 *
 * Bewusst kein Kernfeld am Termin: iCalendar kennt `CATEGORIES` als freie
 * Liste, aber ob eine Anwendung ueberhaupt Kategorien fuehrt — und ob sie
 * verwaltet oder frei sind — beantwortet jede fuer sich. Eine Spalte in der
 * geteilten Terminzeile haette allen dieselbe Antwort aufgezwungen.
 *
 * Wo die Zuordnung Termin -> Kategorie liegt, entscheidet ebenfalls die
 * Anwendung: im Profil ihrer Terminart oder in einer eigenen Spalte ihrer
 * uebernommenen Tabelle. Das Paket besitzt die Liste, nicht die Verbindung.
 */
class CalendarCategory extends Model
{
    /** @see CalendarEvent::$guarded — dieselbe Begruendung, dieselbe Falle. */
    protected $guarded = [];

    public function getTable(): string
    {
        return config('calendar.tables.categories', 'calendar_categories');
    }

    protected function casts(): array
    {
        return [
            static::column('is_active') => 'boolean',
        ];
    }

    /**
     * Spaltenname dieser Tabelle.
     *
     * Zuerst die tabellenbezogene Abbildung, dann die gemeinsame. Der Grund
     * ist ein Feld, das es in BEIDEN Tabellen gibt: `owner_id`. Wer seine
     * Termine auf `user_id` abbildet, hat damit auch die Kategorien darauf
     * abgebildet — was zufaellig passen kann und ebenso zufaellig nicht.
     *
     * Die gemeinsame Abbildung bleibt der Rueckfall, damit bestehende
     * Konfigurationen unveraendert weiterlaufen.
     */
    public static function column(string $field): string
    {
        // `kind` nimmt die gemeinsame Abbildung NICHT.
        //
        // Flach bedeutet `kind` die Terminart AM TERMIN. Hier bedeutet es, fuer
        // welche Art diese Kategorie angeboten wird — zwei verschiedene Dinge
        // mit demselben Namen. Wer seine Termine auf `event_type` abbildet,
        // haette damit auch die Kategorienspalte umbenannt, und die Abfrage
        // suchte eine Spalte, die es nicht gibt.
        //
        // Dieselbe Falle wie bei `owner_id`, nur eine Stufe schaerfer: Dort
        // KANN die gemeinsame Abbildung zufaellig passen, hier nie. Und weil
        // die Spalte neu ist, kann sich keine bestehende Konfiguration auf den
        // Rueckfall verlassen — ihn abzuschneiden bricht also nichts.
        if ($field === 'kind') {
            return config('calendar.columns.categories.kind', 'kind');
        }

        return config("calendar.columns.categories.{$field}")
            ?? config("calendar.columns.{$field}", $field);
    }

    public function field(string $name): mixed
    {
        return $this->getAttribute(static::column($name));
    }

    /**
     * Nur die Felder behalten, die diese Tabelle JETZT hat.
     *
     * ## Warum das noetig ist
     *
     * Eine Wanderung aus dem September 2026 im AI Brain ruft
     * {@see \Peppermint\Calendar\Categories\CategoryRegistry::seed()} auf.
     * Als sie geschrieben wurde, gab es die Spalte `kind` nicht — und an ihrer
     * Stelle in der Reihenfolge gibt es sie auch heute nicht, denn die
     * Wanderung, die sie anlegt, kommt spaeter. In einem frischen Aufbau
     * (Tests, Neuinstallation, neuer Server) brach deshalb jeder Lauf mit
     *
     *     table calendar_categories has no column named kind
     *
     * und zwar beim AUFBAU, nicht in einem Test — 57 Tests mit null
     * Zusicherungen, und der Fehler zeigte auf eine Datei, die seit einem
     * Monat unveraendert ist.
     *
     * Die Wanderung ist in der Zeit eingefroren, der Dienst nicht. Weil das
     * Paket die Spalte eingefuehrt hat, gehoert die Ruecksicht hierher und
     * nicht in die Wanderung jedes Produkts.
     *
     * ## Warum das nichts verschluckt
     *
     * Im normalen Betrieb sind alle Spalten da, der Filter ist dann ein
     * Durchlauf. Er greift ausschliesslich waehrend einer Wanderung, die
     * mitten in der eigenen Entwicklungsgeschichte steht — und dort ist ein
     * fehlendes `kind` die richtige Antwort, denn leer heisst „fuer jede
     * Terminart".
     *
     * @param  array<string, mixed>  $werte
     * @return array<string, mixed>
     */
    public static function onlyExistingColumns(array $werte): array
    {
        $vorhanden = Schema::getColumnListing((new static)->getTable());

        return array_intersect_key($werte, array_flip($vorhanden));
    }

    /**
     * Die Termine, die diese Kategorie tragen.
     *
     * Nur die ueber die Zuordnungstabelle des Pakets. Haelt eine Terminart
     * ihre Kategorie woanders — wie `BusinessKind` im Manager, der sie im
     * Profil fuehrt —, dann weiss das nur sie. Diese Beziehung ist deshalb
     * eine UNTERGRENZE, und wer darauf eine Loeschung stuetzt, verweigert
     * besser bei jedem Treffer als abzuwaegen.
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(
            CalendarEvent::class,
            config('calendar.tables.event_categories', 'calendar_event_categories'),
            'category_id',
            'event_id',
        );
    }

    /** Gemeinsame Kategorien und die eigenen dieser Person — nichts von anderen. */
    public function scopeVisibleTo(Builder $query, ?int $userId): Builder
    {
        return $query->where(function (Builder $inner) use ($userId) {
            $inner->whereNull(static::column('owner_id'));

            if ($userId !== null) {
                $inner->orWhere(static::column('owner_id'), $userId);
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(static::column('is_active'), true);
    }

    /**
     * Die Kategorien, die diese Terminart anbietet.
     *
     * Eine Kategorie ohne Art gilt fuer jede — das ist der Rueckfall und der
     * Zustand jeder Zeile, die vor der Spalte entstanden ist. Eine Kategorie
     * MIT Art gilt nur dort.
     *
     * Ohne Art in der Frage (`null`) wird nicht eingegrenzt: Die Pflegemaske
     * und die Auswertung wollen die ganze Liste sehen, nicht den Ausschnitt
     * einer Art.
     *
     * Das ist der Unterschied, der beides in einer Liste haelt: Im AI Brain
     * bietet `private` seine sieben Lebensbereiche an, ein Kundentermin die
     * sechs Arbeitskategorien — und im CRM gibt es die Art `private` gar
     * nicht, dort bleiben also nur die sechs. Keine Ausnahme je Produkt,
     * dieselbe Regel.
     */
    public function scopeForKind(Builder $query, ?string $kind): Builder
    {
        if ($kind === null) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($kind) {
            $inner->whereNull(static::column('kind'))
                ->orWhere(static::column('kind'), $kind);
        });
    }
}
