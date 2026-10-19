<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Peppermint\Calendar\Models\CalendarEvent;

/**
 * Ein Termin, dessen Teilnehmer aus einem produkteigenen Modell kommen.
 *
 * Erweitert die Paketklasse und überschreibt NUR die Beziehung — genau das
 * Muster, das der Manager benutzt. Mehr braucht es nicht, um den Fall
 * abzudecken, an dem die Einzelansicht in Produktion zerbrochen ist.
 */
class TerminMitFremdenTeilnehmern extends CalendarEvent
{
    public function attendees(): HasMany
    {
        return $this->hasMany(FremderTeilnehmer::class, 'event_id');
    }
}
