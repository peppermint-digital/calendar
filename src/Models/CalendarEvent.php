<?php

namespace Peppermint\Calendar\Models;

use Peppermint\Calendar\Exceptions\ProfileFieldsWithoutProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Peppermint\Calendar\Eloquent\CalendarEventBuilder;
use Peppermint\Calendar\Exceptions\AttributeWithoutHome;
use Peppermint\Calendar\Exceptions\ForbiddenAttributeForKind;
use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Kinds\EventKindRegistry;

class CalendarEvent extends Model
{
    use SoftDeletes;

    public function getTable(): string
    {
        return config('calendar.tables.events', 'calendar_events');
    }

    /**
     * Empty rather than ['id']: a non-empty guard makes Eloquent ask the table
     * for its column list and cache that answer statically, per model class.
     * This model can be pointed at a different table at runtime, so a cached
     * column list from an earlier table would silently drop every attribute the
     * new one does not share. Mass assignment is the application's business —
     * it validates its own requests.
     */
    protected $guarded = [];

    protected function casts(): array
    {
        // Keyed by the real column names: an adopted table calls them something
        // else, and a cast registered under a column that does not exist is a
        // cast that never runs.
        return [
            static::column('starts_at') => 'datetime',
            static::column('ends_at') => 'datetime',
            static::column('all_day') => 'boolean',
            'is_recurrence_master' => 'boolean',
            'recurrence_rules' => 'array',
            'recurrence_exceptions' => 'array',
            'recurrence_until' => 'date:Y-m-d',
        ];
    }

    /**
     * Flach uebergebene Profilfelder, die auf ihre Profilzeile warten.
     *
     * @var array<string, mixed>
     */
    protected array $profileAttributes = [];

    /**
     * Die Profilzeile, einmal geholt.
     *
     * Eigenes Feld und NICHT `$this->relations['profile']`: `refresh()` laedt
     * jede geladene Beziehung nach — und baut sie dabei auf einer frischen,
     * LEEREN Modellinstanz. Die hat keine Terminart, also kein Profilmodell,
     * also keine Beziehung. Ein Profil, das je Art ein anderes Modell ist,
     * laesst sich ueber eine einzelne `hasOne` grundsaetzlich nicht eager
     * laden; wer es in die Beziehungen legt, bricht `refresh()` und `load()`
     * fuer jeden Termin, der einmal ein Profilfeld gelesen hat.
     */
    protected ?Model $profileRow = null;

    protected bool $profileLoaded = false;

    /**
     * Spaltenlisten je Verbindung und Tabelle.
     *
     * @var array<string, array<int, string>>
     */
    protected static array $columnCache = [];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $uid = static::column('uid');

            $event->{$uid} ??= (string) Str::uuid();
        });

        static::saving(function (self $event): void {
            // Throws when the kind is unknown. Deliberately no fallback to a
            // default: a guessed event kind decides fields, visibility and
            // deletion behaviour.
            $kind = $event->kindDefinition();

            foreach ($kind->forbiddenAttributes() as $attribute) {
                // Only what is being set or changed right now. A value that an
                // existing row already carries is not this check's business:
                // applications adopt kinds for data that predates them, and a
                // guard that rejects an untouched legacy value turns "you may
                // not add this" into "you may never save this row again".
                if ($event->exists && ! $event->isDirty($attribute)) {
                    continue;
                }

                if (filled($event->getAttribute($attribute))) {
                    throw ForbiddenAttributeForKind::make($kind->key(), $attribute);
                }
            }

            $kind->saving($event);

            $event->liftProfileAttributes($kind);
        });

        // Nach dem Speichern, nicht davor: Vorher hat die Zeile noch keine
        // Kennung, auf die das Profil zeigen koennte.
        static::saved(function (self $event): void {
            $event->writeProfileAttributes();
        });
    }

    /**
     * Nimmt die flach uebergebenen Profilfelder aus den Attributen heraus.
     *
     * Solange die Kernspalte noch existiert, bleibt der Wert ZUSAETZLICH dort
     * stehen: Ein Rueckbau der Anwendung findet ihn dann an der alten Stelle
     * vor, und die Lesestelle unten liest ihn weiter von dort. Erst wenn die
     * Spalte gefallen ist, wird der Wert hier herausgenommen — sonst scheiterte
     * das INSERT an einer Spalte, die es nicht mehr gibt.
     */
    protected function liftProfileAttributes(EventKind $kind): void
    {
        $felder = $kind->profileFields();

        foreach ($felder as $field) {
            if (! array_key_exists($field, $this->attributes)) {
                continue;
            }

            $this->profileAttributes[$field] = $this->attributes[$field];

            if (! $this->hasRealColumn($field)) {
                unset($this->attributes[$field]);
            }
        }

        $this->raeumeHeimatloseWerteWeg($kind, $felder);
    }

    /**
     * Was weder Spalte noch Feld dieser Art ist, kann nicht gespeichert werden.
     *
     * ## Warum es diesen Schritt braucht
     *
     * Gewachsene Anwendungen schicken beim Speichern ALLE Felder der Maske
     * mit, auch die leeren — `'organization_id' => null` steht dann auch an
     * einem Privattermin im Array. Solange die Kerntabelle breit war, nahm sie
     * das stumm auf. Genau diese Breite soll weg, und sobald die Spalte faellt,
     * bricht dasselbe INSERT ab:
     *
     *     table calendar_events has no column named organization_id
     *
     * Ein leerer Wert fuer ein Feld, das diese Art nicht hat, sagt nichts aus.
     * Er wird weggelassen — dabei geht nichts verloren, weil nichts da war.
     *
     * ## Ein BELEGTER Wert dagegen wirft
     *
     * Wer `meeting_url` an einem Privattermin uebergibt, haelt sie fuer
     * gespeichert. Sie still wegzulassen waere der Datenverlust, den der ganze
     * Umzug vermeidet; sie in die Kerntabelle zu schreiben, war das Problem.
     * Also laut.
     *
     * `forbiddenAttributes()` greift vorher und ist etwas anderes: Dort steht,
     * was eine Art nicht tragen DARF, auch wenn der Kern eine Spalte dafuer
     * hat. Hier geht es darum, dass es ueberhaupt keinen Ort gibt.
     *
     * @param  array<int, string>  $felder
     */
    protected function raeumeHeimatloseWerteWeg(EventKind $kind, array $felder): void
    {
        foreach (array_keys($this->attributes) as $key) {
            if ($this->hasRealColumn($key) || in_array($key, $felder, true)) {
                continue;
            }

            if (filled($this->attributes[$key])) {
                throw AttributeWithoutHome::make($kind->key(), $key);
            }

            unset($this->attributes[$key]);
        }
    }

    /**
     * Schreibt die gesammelten Felder ins Profil.
     *
     * `updateOrCreate`, weil dasselbe Feld beim Anlegen und beim Aendern
     * denselben Weg nimmt — und weil ein Termin sein Profil auch dann bekommen
     * soll, wenn er vor dem Umzug ohne eines entstanden ist.
     */
    protected function writeProfileAttributes(): void
    {
        if ($this->profileAttributes === []) {
            return;
        }

        $werte = $this->profileAttributes;
        $this->profileAttributes = [];

        $beziehung = $this->profile();

        if ($beziehung === null) {
            // Die Art nennt Profilfelder, hat aber kein Profilmodell. Das ist
            // ein Fehler in der Art, kein Zustand, der sich still korrigieren
            // laesst: Die Werte haetten nirgends einen Platz.
            throw ProfileFieldsWithoutProfile::make($this->kindDefinition()->key());
        }

        $beziehung->updateOrCreate([], $werte);

        $this->profileRow = null;
        $this->profileLoaded = false;
    }

    /**
     * Liest ein Profilfeld, wenn der Kern dazu nichts sagt.
     *
     * Reihenfolge mit Absicht: erst der Kern, dann das Profil. Zeilen von vor
     * dem Umzug tragen ihren Wert noch in der Kernspalte, und die ist die
     * Wahrheit, solange sie existiert. Faellt sie, antwortet das Profil — ohne
     * dass eine einzige Lesestelle sich aendert.
     */
    public function getAttribute($key)
    {
        $wert = parent::getAttribute($key);

        if ($wert !== null || ! is_string($key)) {
            return $wert;
        }

        if (array_key_exists($key, $this->profileAttributes)) {
            return $this->profileAttributes[$key];
        }

        // Die Art selbst darf hier nicht nachgefragt werden: Sie ist die
        // Bedingung dafuer, dass es ueberhaupt Profilfelder gibt — ein Blick
        // auf sie ueber diesen Weg wuerde sich endlos im Kreis drehen.
        if ($key === static::column('kind')) {
            return $wert;
        }

        $art = $this->attributes[static::column('kind')] ?? null;

        // Ein Lesezugriff darf nicht werfen. `kindDefinition()` tut das bei
        // einer unbekannten Art — mit Recht, denn beim SPEICHERN waere eine
        // geratene Art schlimmer als ein Abbruch. Hier ist die Lage anders:
        // Das Modell kann halb hydriert sein (`select('id')`), die Art kann
        // leer durch ein Formular gekommen sein, und in beiden Faellen ist die
        // richtige Antwort „der Kern sagt dazu nichts" — nicht eine Ausnahme
        // aus einem Getter, die den Aufrufer an einer Stelle trifft, an der er
        // nur ein Feld lesen wollte.
        if (! is_string($art) || $art === '' || ! static::kinds()->has($art)) {
            return $wert;
        }

        if (! in_array($key, static::kinds()->get($art)->profileFields(), true)) {
            return $wert;
        }

        if (! $this->profileLoaded) {
            $this->profileRow = $this->profile()?->getResults();
            $this->profileLoaded = true;
        }

        return $this->profileRow?->getAttribute($key);
    }

    /**
     * Gibt es diese Spalte in der Kerntabelle (noch)?
     *
     * Einmal je Tabelle und Verbindung erfragt. Die Antwort aendert sich genau
     * dann, wenn eine Migration laeuft — in Tests also durchaus mitten im Lauf,
     * deshalb `forgetColumns()`.
     */
    protected function hasRealColumn(string $field): bool
    {
        $schluessel = $this->getConnectionName().'|'.$this->getTable();

        static::$columnCache[$schluessel] ??= $this->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($this->getTable());

        return in_array($field, static::$columnCache[$schluessel], true);
    }

    public static function forgetColumns(): void
    {
        static::$columnCache = [];
    }

    /**
     * The real column name for one of the package's fields, which may differ in
     * an application that adopted an existing table.
     */
    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): CalendarEventBuilder
    {
        return new CalendarEventBuilder($query);
    }

    public static function column(string $field): string
    {
        return config("calendar.columns.events.{$field}")
            ?? config("calendar.columns.{$field}", $field);
    }

    /**
     * Reads a package field through whatever the column is actually called, so
     * package code can say `$event->field('starts_at')` without knowing.
     */
    public function field(string $name): mixed
    {
        return $this->getAttribute(static::column($name));
    }

    public function kindDefinition(): EventKind
    {
        return static::kinds()->get((string) $this->field('kind'));
    }

    /**
     * Jede Neubefuellung der Attribute verwirft das gemerkte Profil.
     *
     * Deckt beides ab: das Hydrieren aus der Datenbank und `refresh()`. Ohne
     * das wuerde ein aufgefrischter Termin das Profil von vorhin weiterlesen.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->profileRow = null;
        $this->profileLoaded = false;

        return parent::setRawAttributes($attributes, $sync);
    }

    protected static function kinds(): EventKindRegistry
    {
        return app(EventKindRegistry::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(config('calendar.user_model'), static::column('owner_id'));
    }

    /**
     * What the event is about, when it refers to something outside the calendar
     * — the task behind a time block, for instance. Null for a plain appointment.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, static::column('subject_type'), static::column('subject_id'));
    }

    public function scopeAbout(Builder $query, string $subjectType, int|array $subjectIds): Builder
    {
        return $query->where(static::column('subject_type'), $subjectType)
            ->whereIn(static::column('subject_id'), (array) $subjectIds);
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
        return $query->whereIn(static::column('kind'), $kinds);
    }

    /**
     * Events overlapping the window — not merely those starting inside it. An
     * event running from 23:00 yesterday to 01:00 today belongs in today's view.
     */
    public function scopeInRange(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->where(static::column('starts_at'), '<', $to)
            ->where(static::column('ends_at'), '>', $from);
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
            $q->where(static::column('owner_id'), $userId)
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
