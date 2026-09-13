import { useMemo } from 'react';
import { FIELD, type EventDraft, type EventKind, type FieldKey } from './types';

/**
 * Die Regeln einer Terminart, uebersetzt in Fragen, die ein Formular stellen kann.
 *
 * Getrennt von der Darstellung, damit ein Produkt sie auch anders zeichnen
 * kann, ohne die Regeln neu zu schreiben — genau die Doppelpflege, die hier
 * vermieden werden soll.
 */
export function useEventForm(kinds: EventKind[], draft: EventDraft) {
    return useMemo(() => {
        const kind = kinds.find((entry) => entry.key === draft.kind) ?? kinds[0];
        const requires = new Set(kind?.requires ?? []);
        const forbids = new Set(kind?.forbids ?? []);

        const shows = (field: FieldKey) => !forbids.has(FIELD[field]);
        const needs = (field: FieldKey) => requires.has(FIELD[field]);

        // Was das Formular selbst beantworten kann, ohne den Server zu fragen.
        // Ein Absenden, das drueben an einer Bedingung scheitert, die hier
        // schon sichtbar war, ist eine vermeidbare Enttaeuschung.
        const missing = (Object.keys(FIELD) as FieldKey[]).filter((field) => {
            if (! needs(field) || ! shows(field)) {
                return false;
            }

            if (field === 'start' || field === 'end') {
                return ! draft.allDay && draft[field] === '';
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
    }, [kinds, draft]);
}
