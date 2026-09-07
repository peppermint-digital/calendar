<?php

namespace Peppermint\Calendar\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Peppermint\Calendar\Exceptions\ForbiddenAttributeForKind;
use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Kinds\EventKindRegistry;

class CalendarEvent extends Model
{
    use SoftDeletes;

    protected $table = 'calendar_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'is_recurrence_master' => 'boolean',
            'recurrence_rules' => 'array',
            'recurrence_until' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->uid ??= (string) Str::uuid();
        });

        static::saving(function (self $event): void {
            // Wirft, wenn die Art unbekannt ist. Absichtlich kein Rückfall auf
            // eine Vorgabe: eine geratene Terminart entscheidet über Felder,
            // Sichtbarkeit und Löschverhalten.
            $kind = $event->kindDefinition();

            foreach ($kind->forbiddenAttributes() as $attribute) {
                if (filled($event->getAttribute($attribute))) {
                    throw ForbiddenAttributeForKind::make($kind->key(), $attribute);
                }
            }

            $kind->saving($event);
        });
    }

    public function kindDefinition(): EventKind
    {
        return app(EventKindRegistry::class)->get((string) $this->kind);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(config('calendar.user_model'), 'owner_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(CalendarEventAttendee::class, 'event_id');
    }

    /**
     * Die Zusatzfelder dieser Terminart. Welche Tabelle das ist, weiss nur die
     * Art — der Kern hat keine Spalte dafür und bekommt auch keine.
     */
    public function profile(): ?HasOne
    {
        $model = $this->kindDefinition()->profileModel();

        return $model === null ? null : $this->hasOne($model, 'event_id');
    }

    public function scopeOfKind(Builder $query, string ...$kinds): Builder
    {
        return $query->whereIn('kind', $kinds);
    }

    /**
     * Termine, die sich mit dem Zeitraum überschneiden — nicht nur die, die
     * darin beginnen. Ein Termin von gestern 23:00 bis heute 01:00 gehört in
     * die Ansicht von heute.
     */
    public function scopeInRange(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    /**
     * Besitz ODER Teilnahme. Ohne Nutzer wird nichts freigegeben: fehlende
     * Identität heisst "unbekannt", nicht "egal". Ein Scope, der bei fehlender
     * Identität die Einschränkung fallen lässt, gibt genau dann alles heraus,
     * wenn am wenigsten über den Aufrufer bekannt ist.
     */
    public function scopeVisibleTo(Builder $query, ?int $userId): Builder
    {
        if ($userId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($userId): void {
            $q->where('owner_id', $userId)
                ->orWhereHas('attendees', fn (Builder $a) => $a->where('user_id', $userId));
        });
    }

    /**
     * Löschen richtet sich nach der Terminart: Arten mit Papierkorb wandern in
     * den Papierkorb, Arten ohne verschwinden sofort — samt Profil und
     * Teilnehmern über die Fremdschlüssel.
     */
    protected function performDeleteOnModel(): void
    {
        if ($this->forceDeleting || ! $this->kindDefinition()->usesTrash()) {
            // Angaben zum Termin gehen mit: Teilnahme und Profil sind ohne ihn
            // keine Auskunft, die jemand später noch braucht. Bewusst im Code
            // und nicht nur als Fremdschlüssel-Regel — ob ein Cascade greift,
            // hängt am Treiber (SQLite braucht ein aktives Pragma, MySQL nicht),
            // und ein Verhalten, das in Tests und Produktion auseinanderläuft,
            // ist an dieser Stelle keins.
            $this->attendees()->delete();
            $this->deleteProfile();

            $this->setKeysForSaveQuery($this->newModelQuery())->forceDelete();
            $this->exists = false;

            return;
        }

        $this->runSoftDelete();
    }

    protected function deleteProfile(): void
    {
        $profile = $this->profile();

        $profile?->delete();
    }
}
