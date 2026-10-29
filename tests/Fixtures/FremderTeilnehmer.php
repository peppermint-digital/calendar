<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Teilnehmermodell, das die Paketklasse NICHT erweitert.
 *
 * Genau so steht es im Manager: `App\Models\CalendarEventAttendee` ist ein
 * eigenes Modell auf derselben Tabelle, und `CalendarEvent::attendees()` ist
 * dort überschrieben, damit es benutzt wird.
 *
 * Das ist erlaubt — ein Produkt darf seine Beziehungen austauschen. Deshalb
 * darf Paketcode nicht auf die eigene Klasse typisieren. Dass er es tat, hat
 * am 19.10.2026 die Einzelansicht des Managers mit einem TypeError zerlegt,
 * und zwar NUR bei Terminen mit Teilnehmern (#8307).
 */
class FremderTeilnehmer extends Model
{
    protected $table = 'calendar_event_attendees';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Die Antwort als ENUM — auch das macht der Manager so
     * (`App\AttendeeStatus`).
     *
     * Dazugekommen am 29.10.2026, und es hat sofort etwas gefunden: Der
     * Presenter pruefte die Antwort mit `in_array(..., true)` gegen drei
     * Strings, und gegen ein Enum ist das IMMER falsch. In der Einzelansicht
     * des Managers stand deshalb hinter keinem Teilnehmer eine Antwort, auch
     * wenn er zugesagt hatte. Kein Fehler, keine leere Liste — nur eine
     * Angabe, die nie erscheint.
     *
     * Die Attrappe gehoert also nicht nur dem TypeError von #8307, sondern
     * jedem Unterschied, den ein produkteigenes Modell mitbringt. Das ist der
     * Grund, warum sie das Paket NICHT erweitert.
     */
    protected $casts = [
        'status' => FremdeAntwort::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(DemoUser::class, 'user_id');
    }
}

/**
 * Das Vokabular, mit dem ein Produkt die Antwortspalte castet.
 *
 * Das Paket hat keines und soll keines haben — die Worte gehoeren dem
 * Produkt. Hier steht es nur, damit die Attrappe eines haben kann.
 */
enum FremdeAntwort: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Tentative = 'tentative';
}
