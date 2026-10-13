<?php

namespace Peppermint\Calendar\Schema;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Exceptions\IdentifierTooLong;

/**
 * Die Tabellen, die sich an einen Termin haengen — als eine Zeile Migration.
 *
 * Es gibt genau zwei Bauformen, und sie zu verwechseln ist der Fehler, der eine
 * Kerntabelle breit macht:
 *
 * **Profil** — 1:1, gehoert EINER Terminart. Nur Geschaeftstermine haben eine
 * Kategorie. Eine Art ohne eigene Felder braucht keines.
 *
 * **Satellit** — gehoert einem MODUL und gilt fuer JEDE Art. Teilnehmer sind
 * das Vorbild: `calendar_event_attendees` haengt an `event_id` und funktioniert
 * fuer Urlaub, Zeitblock und Gewohnheit gleichermassen, ohne dass eine dieser
 * Arten davon weiss. Notizen, Tags und Erinnerungen gehoeren genauso gebaut.
 *
 * Der Unterschied in einem Satz: Ein Satellit braucht KEINE Mitwirkung der
 * Terminarten, ein Profilfeld braucht alle.
 */
class CalendarSchema
{
    /**
     * MySQL erlaubt fuer Tabellen, Spalten, Indizes und Fremdschluessel
     * hoechstens 64 Zeichen. SQLite kennt die Grenze nicht.
     */
    public const IDENTIFIER_LIMIT = 64;

    /**
     * Die Profiltabelle eines geplanten Blocks.
     *
     *     CalendarSchema::plannedBlockProfile('brain_task', 'tasks');
     *
     * Legt `calendar_event_profile_brain_task` an: Kennung, `event_id`
     * (eindeutig, faellt mit dem Termin), `task_id` (wird geleert, wenn die
     * Aufgabe geht), Zeitstempel.
     *
     * `event_id` ist EINDEUTIG, weil das Profil 1:1 zum Termin steht — ohne den
     * Index kann ein Termin zwei Profile bekommen, und dann ist nicht
     * entscheidbar, welches gilt.
     *
     * `nullOnDelete` und nicht `cascadeOnDelete`: Dass der Termin mitgeht, wenn
     * der Gegenstand geht, entscheidet die ART ueber `usesTrash()` und ihren
     * Speicher-Haken — nicht die Datenbank hinter dem Ruecken der Anwendung.
     */
    public static function plannedBlockProfile(
        string $kind,
        string $subjectTable,
        ?string $subjectKey = null,
        ?Closure $weitere = null,
    ): void {
        $tabelle = 'calendar_event_profile_'.$kind;
        $spalte = $subjectKey ?? self::ableiten($subjectTable);

        self::pruefeBezeichner($tabelle, [$spalte, 'event_id']);

        Schema::create($tabelle, function (Blueprint $table) use ($subjectTable, $spalte, $weitere) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId($spalte)->nullable()->constrained($subjectTable)->nullOnDelete();
            $table->timestamps();

            $table->unique('event_id');

            if ($weitere !== null) {
                $weitere($table);
            }
        });
    }

    /**
     * Die Tabelle eines Moduls, das sich an jeden Termin haengen kann.
     *
     *     CalendarSchema::satellite('calendar_event_notes', function ($table) {
     *         $table->foreignId('note_id')->constrained()->cascadeOnDelete();
     *     });
     *
     * KEIN eindeutiger Index auf `event_id`: Ein Satellit darf mehrfach an
     * einem Termin haengen — drei Teilnehmer, zwei Notizen, fuenf Tags. Wer
     * 1:1 braucht, setzt den Index in `$weitere`.
     *
     * Hier faellt die Zeile MIT dem Termin (`cascadeOnDelete`): Eine Notiz zu
     * einem geloeschten Termin ist keine Information, die jemand braucht.
     */
    public static function satellite(string $tabelle, ?Closure $weitere = null): void
    {
        self::pruefeBezeichner($tabelle, ['event_id']);

        Schema::create($tabelle, function (Blueprint $table) use ($weitere) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->timestamps();

            $table->index('event_id');

            if ($weitere !== null) {
                $weitere($table);
            }
        });
    }

    /**
     * Haelt die 64-Zeichen-Grenze von MySQL ein, bevor die Migration laeuft.
     *
     * Laravel setzt Index- und Fremdschluesselnamen aus Tabelle, Spalte und
     * einem Zusatz zusammen. Bei einer Profiltabelle, deren Name die Terminart
     * enthaelt, und einer Spalte, die den Gegenstand nennt, addiert sich das
     * schneller als man zaehlt:
     *
     *     calendar_event_profile_resource_booking_resource_booking_id_foreign
     *     = 67 Zeichen
     *
     * Am 13.10.2026 hat genau dieser Name drei Deploys lang jeden Rollout von
     * `pm.peppermint-digital.com` aufgehalten. SQLite kennt die Grenze nicht —
     * 2425 Tests waren gruen, und die Migration ist auf dem Produktionsserver
     * mitten im Lauf gestorben, nachdem sie drei von vier Tabellen angelegt
     * hatte. Weil Laravel den Eintrag in `migrations` erst NACH dem Durchlauf
     * schreibt, versuchte jeder weitere Deploy sie von vorn.
     *
     * Deshalb steht die Pruefung hier und nicht im Test der Anwendung: Sie
     * greift fuer jeden, der dieses Paket benutzt, und sie greift, bevor die
     * erste Anweisung laeuft.
     *
     * @param  array<int, string>  $spalten
     */
    public static function pruefeBezeichner(string $tabelle, array $spalten): void
    {
        if (strlen($tabelle) > self::IDENTIFIER_LIMIT) {
            throw IdentifierTooLong::table($tabelle);
        }

        foreach ($spalten as $spalte) {
            foreach (['foreign', 'unique', 'index'] as $zusatz) {
                $name = "{$tabelle}_{$spalte}_{$zusatz}";

                if (strlen($name) > self::IDENTIFIER_LIMIT) {
                    throw IdentifierTooLong::index($name, $tabelle, $spalte);
                }
            }
        }
    }

    private static function ableiten(string $subjectTable): string
    {
        return rtrim($subjectTable, 's').'_id';
    }
}
