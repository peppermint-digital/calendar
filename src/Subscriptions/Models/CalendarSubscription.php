<?php

namespace Peppermint\Calendar\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein abonnierter fremder Kalender — eine ICS-Adresse, die jemand eingetragen
 * hat.
 *
 * ## Was ein Abo NICHT ist
 *
 * Keine {@see \Peppermint\Calendar\Sources\EventSource}. Eine Quelle liest zur
 * LAUFZEIT und speichert nichts; ein Abo holt einmal je Stunde und legt die
 * Termine hin. Der Unterschied ist nicht Geschmack: Ein Outlook-Feed antwortet
 * in Sekunden, nicht in Millisekunden, und niemand will darauf warten, bis die
 * Kalenderseite erscheint. Dafuer altert das Gespeicherte — darum der Abgleich.
 *
 * ## Warum das im Paket liegt und die Daten im Brain
 *
 * Die Mechanik gehoert ins Paket, weil sie in jedem Produkt gleich aussaehe
 * (Pruefrage 3). Die DATEN gehoeren in die Nabe, weil ein Abo dem Menschen
 * gehoert und nicht einem Produkt: Wer sein Outlook abonniert, will es in allen
 * vier Kalendern sehen. Entschieden am 29.10.2026, wortwoertlich: „Das sollte
 * schon lange im Paket sein. Das sollte gar nicht mehr im Manager verfuegbar
 * sein."
 *
 * Es ist derselbe Weg, den die privaten Termine im September gegangen sind
 * (#3529, #4823) — sie entstehen im Brain, und die Produkte zeigen sie an.
 *
 * @property int $user_id
 * @property string $name
 * @property string $url
 * @property bool $is_active
 */
class CalendarSubscription extends Model
{
    /**
     * Leer statt `['id']`: Ein nicht-leeres `$guarded` fragt die Tabelle nach
     * ihren Spalten und merkt sich die Antwort statisch je Modellklasse. Dieses
     * Modell kann zur Laufzeit auf eine andere Tabelle zeigen.
     */
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('calendar.tables.subscriptions', 'external_calendars');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscribedEvent::class, 'external_calendar_id');
    }

    /**
     * Wem das Abo gehoert.
     *
     * Ueber `calendar.user_model` und nicht ueber eine feste Klasse — das Paket
     * kennt kein Benutzermodell, es bekommt eines genannt.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo((string) config('calendar.user_model'), 'user_id');
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<self>  $query */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
