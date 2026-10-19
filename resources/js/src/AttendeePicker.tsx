import { useState } from 'react';

import { Input } from './ui/input';

export type PickableAttendee = {
    id: number;
    name: string;
    selected: boolean;
    /** Zweite Zeile im Fähnchen — Abteilung, E-Mail, Rolle. Optional. */
    hint?: string | null;
};

export type AttendeePickerLabels = {
    who: string;
    search: string;
    noMatch: string;
    /** 3 → „3 ausgewählt". */
    selectedCount: (count: number) => string;
};

export const defaultAttendeePickerLabels: AttendeePickerLabels = {
    who: 'Wer:',
    search: 'Person suchen',
    noMatch: 'Niemand gefunden.',
    selectedCount: (count) => `${count} ausgewählt`,
};

export type AttendeePickerProps = {
    attendees: PickableAttendee[];
    /**
     * Gerufen mit der EINEN Person, die umgeschaltet wurde.
     *
     * Nicht mit der neuen Gesamtliste: Das Produkt lädt daraufhin die Seite neu
     * (die Belegung liegt auf dem Server), und dabei ist eine einzelne
     * Umschaltung die kleinere Behauptung.
     */
    onToggle: (id: number) => void;
    /**
     * Ab wie vielen Personen ein Suchfeld erscheint.
     *
     * Der Manager hat gemessen am 19.10.2026 deutlich mehr Leute als CRM und
     * Verwaltung; eine Reihe aus vierzig Fähnchen ist keine Auswahl mehr,
     * sondern eine Wand. Unterhalb der Schwelle wäre ein Suchfeld dagegen nur
     * ein zusätzlicher Klick vor einer Liste, die man ohnehin ganz sieht.
     */
    searchFrom?: number;
    labels?: Partial<AttendeePickerLabels>;
    className?: string;
};

/**
 * Wen fragt man?
 *
 * ## Warum ein Baustein und nicht zwei
 *
 * Es gab zwei: CRM und Verwaltung zeigten eine Reihe umschaltbarer Fähnchen,
 * der Manager ein Suchfeld in einem Aufklapper mit Abteilungen. Beide
 * beantworten dieselbe Frage, und welche Form richtig ist, hängt nur an EINER
 * Zahl — wie viele Leute zur Wahl stehen (#8307).
 *
 * Deshalb hier beides in einem: Fähnchen immer, Suchfeld ab `searchFrom`. So
 * sieht die Seite in allen Produkten gleich aus und wird trotzdem nicht zur
 * Wand, wo es viele Personen gibt.
 *
 * ## Warum die Gewählten immer sichtbar bleiben
 *
 * Die Suche filtert nur die UNGEWÄHLTEN. Wer „Mül" tippt, soll nicht aus den
 * Augen verlieren, dass noch drei andere angehakt sind — sonst schickt er eine
 * Einladung an eine Runde, die er gerade nicht sieht.
 */
export function AttendeePicker({
    attendees,
    onToggle,
    searchFrom = 12,
    labels,
    className = '',
}: AttendeePickerProps) {
    const t = { ...defaultAttendeePickerLabels, ...labels };

    const [suche, setSuche] = useState('');

    const gewaehlt = attendees.filter((person) => person.selected);
    const uebrige = attendees.filter((person) => !person.selected);

    const begriff = suche.trim().toLowerCase();
    const gefiltert =
        begriff === ''
            ? uebrige
            : uebrige.filter(
                  (person) =>
                      person.name.toLowerCase().includes(begriff) ||
                      (person.hint ?? '').toLowerCase().includes(begriff),
              );

    const sichtbar = [...gewaehlt, ...gefiltert];

    return (
        <div className={`space-y-2 ${className}`} data-testid="attendee-picker">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground text-xs">{t.who}</span>

                {attendees.length >= searchFrom && (
                    <Input
                        type="search"
                        value={suche}
                        onChange={(ereignis) => setSuche(ereignis.target.value)}
                        placeholder={t.search}
                        aria-label={t.search}
                        className="h-7 w-44 text-xs"
                    />
                )}

                {gewaehlt.length > 0 && attendees.length >= searchFrom && (
                    <span className="text-muted-foreground text-xs">{t.selectedCount(gewaehlt.length)}</span>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {sichtbar.map((person) => (
                    <button
                        key={person.id}
                        type="button"
                        aria-pressed={person.selected}
                        title={person.hint ?? undefined}
                        onClick={() => onToggle(person.id)}
                        className={`rounded-md border px-2 py-1 text-xs transition ${
                            person.selected
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-background hover:bg-muted'
                        }`}
                    >
                        {person.name}
                    </button>
                ))}

                {sichtbar.length === 0 && <span className="text-muted-foreground text-xs">{t.noMatch}</span>}
            </div>
        </div>
    );
}
