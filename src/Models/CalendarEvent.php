<?php

namespace Peppermint\Calendar\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
            // Throws when the kind is unknown. Deliberately no fallback to a
            // default: a guessed event kind decides fields, visibility and
            // deletion behaviour.
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

    /**
     * What the event is about, when it refers to something outside the calendar
     * — the task behind a time block, for instance. Null for a plain appointment.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeAbout(Builder $query, string $subjectType, int|array $subjectIds): Builder
    {
        return $query->where('subject_type', $subjectType)
            ->whereIn('subject_id', (array) $subjectIds);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(CalendarEventAttendee::class, 'event_id');
    }

    /**
     * The extra fields of this event kind. Only the kind knows which table that
     * is — the core has no column for it, and will not grow one.
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
     * Events overlapping the window — not merely those starting inside it. An
     * event running from 23:00 yesterday to 01:00 today belongs in today's view.
     */
    public function scopeInRange(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    /**
     * Ownership OR attendance. Without a user nothing is released: a missing
     * identity means "unknown", not "anyone". A scope that drops its restriction
     * when it cannot resolve the caller hands out everything precisely when it
     * knows least.
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
     * Deletion follows the event kind: kinds with a trash bin are soft deleted,
     * kinds without one are removed immediately, profile and attendees included.
     */
    protected function performDeleteOnModel(): void
    {
        if ($this->forceDeleting || ! $this->kindDefinition()->usesTrash()) {
            // Details of the event go with it: attendance and profile are not
            // information anyone needs once the event is gone. Done in code and
            // not only as a foreign key rule — whether a cascade fires depends on
            // the driver (SQLite needs the pragma enabled, MySQL does not), and
            // behaviour that differs between tests and production is not
            // behaviour at all.
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
