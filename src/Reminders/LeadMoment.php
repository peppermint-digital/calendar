<?php

namespace Peppermint\Calendar\Reminders;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Wann vor einem Termin erinnert wird — die Rechnung, EINMAL.
 *
 * ## Warum das eine eigene Klasse ist
 *
 * Weil es sonst zwei gibt. `EventReminders` rechnet es fuer die eigenen
 * Termine; am 29.10.2026 kam im Peppermint Manager ein zweiter Anlass dazu
 * (`ExternalEventReminders` fuer abonnierte fremde Kalender, Bug #1081) und
 * brauchte dieselbe Rechnung samt demselben Ganztags-Sonderfall. Pruefrage 3
 * des Paketvertrags, diesmal beantwortet, BEVOR die zweite Kopie altert.
 *
 * ## Der Ganztagsfall
 *
 * Ein Ganztagstermin beginnt um Mitternacht, „15 Minuten vorher" waere also
 * 23:45 am Vortag — richtig gerechnet und nutzlos. Er bekommt stattdessen den
 * Morgen seines Tages, in ORTSZEIT. Der Vorlauf gilt dort fuer niemanden: Es
 * gibt keine Uhrzeit, von der er abgezogen werden koennte.
 *
 * ## Die Zeitzone, an der es schon einmal haengengeblieben ist
 *
 * Hier stand bis zum 29.10.2026 am Ende `->utc()`, mit der Begruendung „die
 * Spalte speichert UTC". Das ist eine Annahme ueber das PRODUKT und kein
 * Fakt — und im Peppermint Manager ist sie falsch: `app.timezone` steht dort
 * auf `Europe/Berlin`.
 *
 * Eloquent formatiert einen Carbon-Wert beim Speichern in der Zeitzone, die
 * das Objekt gerade traegt, und liest die Spalte beim Lesen in
 * `app.timezone` zurueck. Ein als UTC geschriebenes „08:00 Berlin" landete
 * deshalb als `07:00` in der Spalte und kam als **07:00 Ortszeit** wieder
 * heraus: Die Ganztags-Erinnerung feuerte eine Stunde zu frueh, im Sommer
 * zwei. Keine Ausnahme, kein roter Test — nur eine Uhrzeit, die niemand
 * nachgerechnet hat. Gemessen beim Bauen des zweiten Anlasses; von 21
 * kommenden Terminen im Manager waren 10 ganztaegig.
 *
 * Richtig ist deshalb die Zeitzone der ANWENDUNG: Sie ist die, in der die
 * Spalte wieder gelesen wird. Wo `app.timezone` auf UTC steht, ist das
 * dasselbe wie vorher.
 */
final class LeadMoment
{
    /**
     * @param  int|null  $minuten  Der Vorlauf dieser Person. `null` heisst
     *         „keine Erinnerung" — dann hat dieser Aufruf keinen Sinn, und der
     *         Aufrufer soll vorher abbiegen.
     */
    public static function fuer(DateTimeInterface|string $start, bool $ganztags, int $minuten): CarbonImmutable
    {
        $beginn = CarbonImmutable::parse($start);

        if ($ganztags) {
            $zone = (string) config('calendar.reminders.timezone', 'Europe/Berlin');
            $stunde = (int) config('calendar.reminders.all_day_hour', 8);

            return $beginn
                ->setTimezone($zone)
                ->startOfDay()
                ->addHours($stunde)
                ->setTimezone(self::zoneDerAnwendung());
        }

        // Kein Umrechnen: `$beginn` traegt schon die Zeitzone, in der das
        // Produkt seine Zeiten fuehrt — abziehen aendert daran nichts, und
        // umrechnen wuerde denselben Fehler einfuehren, den der Klassenkopf
        // beschreibt.
        return $beginn->subMinutes($minuten);
    }

    /**
     * Die Zeitzone, in der die Spalte gelesen wird.
     *
     * Ueber `config('app.timezone')` und nicht ueber `date_default_timezone_get()`:
     * Laravel setzt beides gleich, aber die Konfiguration ist die Quelle — und
     * in einem Test laesst sie sich stellen.
     */
    private static function zoneDerAnwendung(): string
    {
        $zone = config('app.timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }
}
