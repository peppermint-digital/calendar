<?php

namespace Peppermint\Calendar\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    public static function column(string $field): string
    {
        return config("calendar.columns.{$field}", $field);
    }

    public function field(string $name): mixed
    {
        return $this->getAttribute(static::column($name));
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
}
