import { FIELD, WEEKDAYS, type EventDraft, type EventKind, type FieldKey, type Weekday } from './types';

/** Der Wochentag eines `YYYY-MM-DD`, als Kuerzel nach RFC 5545. */
export function weekdayOf(date: string): Weekday | null {
    if (date === '') {
        return null;
    }

    const parsed = new Date(`${date}T12:00:00`);

    // `getDay()` zaehlt ab Sonntag, RFC 5545 ab Montag.
    return Number.isNaN(parsed.getTime()) ? null : (WEEKDAYS[(parsed.getDay() + 6) % 7] ?? null);
}

export type EventFormRules = {
    kind: EventKind | undefined;
    shows: (field: FieldKey) => boolean;
    needs: (field: FieldKey) => boolean;
    missing: FieldKey[];
    complete: boolean;
    usesCategories: boolean;
};

/**
 * Die Regeln einer Terminart, uebersetzt in Fragen, die ein Formular stellen kann.
 *
 * Bewusst eine reine Funktion und kein Hook: So laesst sich pruefen, ob eine
 * Art das Richtige verlangt, ohne dafuer React zu starten — und ein Produkt
 * kann dieselben Regeln anders zeichnen, ohne sie neu zu schreiben.
 */
export function eventFormRules(kinds: EventKind[], draft: EventDraft): EventFormRules {
    const kind = kinds.find((entry) => entry.key === draft.kind) ?? kinds[0];
    const requires = new Set(kind?.requires ?? []);
    const forbids = new Set(kind?.forbids ?? []);

    const shows = (field: FieldKey) => ! forbids.has(FIELD[field]);
    const needs = (field: FieldKey) => requires.has(FIELD[field]);

    // Was das Formular selbst beantworten kann, ohne den Server zu fragen.
    // Ein Absenden, das drueben an einer Bedingung scheitert, die hier schon
    // sichtbar war, ist eine vermeidbare Enttaeuschung.
    const missing = (Object.keys(FIELD) as FieldKey[]).filter((field) => {
        if (! needs(field) || ! shows(field)) {
            return false;
        }

        if (field === 'start' || field === 'end') {
            return ! draft.allDay && draft[field] === '';
        }

        if (field === 'recurrence') {
            return draft.recurrence === null;
        }

        // Ein leeres Enddatum heisst „derselbe Tag" und fehlt damit nicht. Es
        // teilt sich `ends_at` mit der Uhrzeit; ohne diesen Riegel verlangte
        // jede Art, die eine Endzeit braucht, auch ein zweites Datum.
        if (field === 'endDate') {
            return false;
        }

        return String(draft[field] ?? '').trim() === '';
    });

    return {
        kind,
        shows,
        needs,
        missing,
        complete: draft.title.trim() !== '' && draft.date !== '' && missing.length === 0,
        usesCategories: kind?.usesCategories === true,
    };
}
