<?php

namespace Peppermint\Calendar\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Termin aus einem abonnierten fremden Kalender.
 *
 * Nur lesbar, was die Anwendung betrifft: Geaendert wird er drueben, hier
 * schreibt ausschliesslich der Abgleich.
 *
 * ## Die zwei Kennungen, und warum es zwei sind
 *
 * `source_uid` ist die `UID` aus dem Feed, unveraendert. `uid` ist der
 * Schluessel DIESER Zeile: `source_uid` plus Startdatum.
 *
 * Der Zusatz ist noetig, weil ein Serientermin beim Auswerten in seine
 * Vorkommen aufgeloest wird — alle tragen dieselbe `UID`, und ohne den Tag
 * wuerde ein Vorkommen das andere ueberschreiben. Dass `uid` damit beim
 * Verschieben auf einen ANDEREN TAG wechselt, hat eine Folge, die man wissen
 * muss: Fuer den Abgleich ist das ein neuer Termin, die alte Zeile faellt weg
 * und eine Erinnerung wird neu gesetzt. Innerhalb desselben Tages zieht sie
 * mit.
 *
 * `source_uid` bleibt daneben stehen, weil nur damit ein Abo-Termin mit einem
 * EIGENEN Termin desselben Hauses zusammengebracht werden kann — die
 * Doppelungsregel braucht ihn ({@see \Peppermint\Calendar\Subscriptions\SubscribedEventReminders}).
 *
 * @property string $uid
 * @property string|null $source_uid
 * @property string $title
 * @property bool $all_day
 */
class SubscribedEvent extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('calendar.tables.subscribed_events', 'external_calendar_events');
    }

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(CalendarSubscription::class, 'external_calendar_id');
    }
}
