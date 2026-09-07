<?php

namespace Peppermint\Calendar\Eloquent;

use Illuminate\Database\Eloquent\Builder;

/**
 * Makes deletion follow the event kind even for bulk deletes.
 *
 * `CalendarEvent::where(...)->delete()` goes through the query builder, where
 * a model's deletion logic never runs — soft-deleting models are updated in
 * place, and a kind that deletes for good would be silently ignored. That turns
 * the rule into something that only applies when the caller happens to know the
 * right form, which is not a rule at all.
 *
 * Rows are loaded in chunks and deleted individually. That is slower than one
 * statement; it is also the only way the per-kind decision survives, and the
 * alternative is a trash bin that fills up with rows that were meant to be gone.
 */
class CalendarEventBuilder extends Builder
{
    public function delete(): int
    {
        $deleted = 0;

        $this->chunkById(500, function ($events) use (&$deleted): void {
            foreach ($events as $event) {
                $event->delete();
                $deleted++;
            }
        });

        return $deleted;
    }
}
