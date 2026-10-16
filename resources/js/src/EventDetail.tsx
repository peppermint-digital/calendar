import type { ReactNode } from 'react';

/**
 * Ein Termin, wie man ihn ansieht — nicht wie man ihn ändert.
 *
 * Bewusst flach und schon lesbar: Wer diese Form füllt, hat die Entscheidung
 * „welche Felder gehören zu einem Termin" schon hinter sich. Was ein Produkt
 * zusätzlich zeigt — Projekt, Aufgabe, Mietliste — hängt es als `extra` an.
 */
export type EventDetailData = {
    title: string;
    /** `YYYY-MM-DD`. */
    date: string;
    /** Bei mehrtägigen Terminen der letzte Tag. */
    endDate?: string | null;
    /** `HH:MM`, oder null bei ganztägig. */
    start?: string | null;
    end?: string | null;
    allDay?: boolean;
    location?: string | null;
    description?: string | null;
    /** Die Terminart, schon beschriftet — „Geschäftlich", nicht `business`. */
    kindLabel?: string | null;
    /** Die Farbe, in der das Raster ihn zeichnet. */
    colour?: string | null;
    recurring?: boolean;
    attendees?: { name: string; status?: string | null }[];
    categories?: string[];
};

export type EventDetailLabels = {
    when: string;
    where: string;
    notes: string;
    attendees: string;
    categories: string;
    series: string;
    allDay: string;
};

const DETAIL_DEFAULTS: EventDetailLabels = {
    when: 'Wann',
    where: 'Wo',
    notes: 'Notiz',
    attendees: 'Teilnehmer',
    categories: 'Kategorien',
    series: 'Teil einer Serie',
    allDay: 'ganztägig',
};

export interface EventDetailProps {
    event: EventDetailData;
    /** Produkteigene Zeilen — Projekt, Aufgabe, Mietliste. */
    extra?: ReactNode;
    /** Bedienelemente: bearbeiten, löschen, zur Aufgabe springen. */
    actions?: ReactNode;
    /**
     * Ein Tag, schon beschriftet.
     *
     * Freigestellt mit deutscher Vorgabe — dieselbe Haltung wie beim
     * Datumsfeld: Das Paket ist für diese vier Systeme gebaut und tut nicht so,
     * als wüsste es nichts über ihre Sprache.
     */
    formatDate?: (iso: string) => string;
    labels?: Partial<EventDetailLabels>;
    className?: string;
}

/** `2026-09-08` → `Di., 08. September 2026`. */
function deutschesDatum(iso: string): string {
    if (iso === '') return '';

    return new Date(`${iso}T00:00:00`).toLocaleDateString('de-DE', {
        weekday: 'short',
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    });
}

/**
 * Die Einzelansicht eines Termins: lesen, nicht ändern.
 *
 * ## Warum sie ins Paket gehört
 *
 * „Einen Termin ansehen" gab es am 16.10.2026 in vier Ausführungen, und zwar
 * in vier verschiedenen *Interaktionsmodellen*: Der Manager hatte eine eigene
 * Seite mit 604 Zeilen, das Brain einen Dialog im Kalender, CRM und Verwaltung
 * führten den Klick direkt auf die Bearbeiten-Maske. Das letzte ist das
 * schlechteste: Es vermischt Lesen mit Ändern, und wer nur nachsehen wollte,
 * wann der Termin ist, steht in einem Formular.
 *
 * Diese Komponente ist nur die Darstellung. Ob sie in einem Dialog steht oder
 * auf einer eigenen Seite, entscheidet das Produkt — beides mit denselben
 * Feldern in derselben Reihenfolge.
 *
 * ## Was sie nicht tut
 *
 * Sie kennt keine Rechte. Ob jemand bearbeiten darf, weiss nur das Produkt
 * (privat, Teilnehmer, Projektleitung, Verwalter) — deshalb kommen die
 * Bedienelemente als `actions` herein und nicht aus einem Schalter hier.
 */
export function EventDetail({
    event,
    extra,
    actions,
    formatDate = deutschesDatum,
    labels,
    className = '',
}: EventDetailProps) {
    const beschriftung = { ...DETAIL_DEFAULTS, ...labels };

    const ganztags = event.allDay === true || (event.start === null && event.end === null);

    /** „Di., 08. September 2026, 09:00 – 10:00" oder über mehrere Tage. */
    const zeitraum = (): string => {
        const beginn = formatDate(event.date);

        // Mehrtägig: Der zweite Tag steht dabei, sonst wäre „ganztägig" eine
        // Lüge über die Länge. Das war im Manager der Fall — ein dreitägiger
        // Termin sah dort aus wie einer von einem Tag.
        if (event.endDate !== null && event.endDate !== undefined && event.endDate !== '' && event.endDate !== event.date) {
            const ende = formatDate(event.endDate);

            return ganztags ? `${beginn} – ${ende}` : `${beginn}, ${event.start} – ${ende}, ${event.end}`;
        }

        if (ganztags) {
            return `${beginn} (${beschriftung.allDay})`;
        }

        return event.end !== null && event.end !== undefined && event.end !== ''
            ? `${beginn}, ${event.start} – ${event.end}`
            : `${beginn}, ${event.start}`;
    };

    return (
        <div data-slot="event-detail" className={`space-y-4 ${className}`.trim()}>
            <div className="space-y-1">
                <div className="flex items-start gap-2">
                    {event.colour !== null && event.colour !== undefined && (
                        <span
                            data-slot="event-detail-dot"
                            className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: event.colour }}
                        />
                    )}
                    <h2 className="text-lg font-semibold">{event.title}</h2>
                </div>

                {event.kindLabel !== null && event.kindLabel !== undefined && event.kindLabel !== '' && (
                    <p className="text-xs text-muted-foreground">{event.kindLabel}</p>
                )}
            </div>

            <dl className="space-y-3 text-sm">
                <div>
                    <dt className="text-xs font-medium text-muted-foreground">{beschriftung.when}</dt>
                    <dd>{zeitraum()}</dd>
                </div>

                {/*
                    Leere Felder bleiben WEG, statt als leere Zeile zu stehen:
                    Ein Etikett ohne Wert liest sich wie ein fehlender Wert, und
                    beim dritten davon hört man auf, die Liste zu lesen.
                */}
                {event.location !== null && event.location !== undefined && event.location !== '' && (
                    <div>
                        <dt className="text-xs font-medium text-muted-foreground">{beschriftung.where}</dt>
                        <dd>{event.location}</dd>
                    </div>
                )}

                {event.description !== null && event.description !== undefined && event.description !== '' && (
                    <div>
                        <dt className="text-xs font-medium text-muted-foreground">{beschriftung.notes}</dt>
                        {/* `whitespace-pre-line`: Wer Absaetze eingetippt hat,
                            soll sie wiederfinden. */}
                        <dd className="whitespace-pre-line">{event.description}</dd>
                    </div>
                )}

                {event.attendees !== undefined && event.attendees.length > 0 && (
                    <div>
                        <dt className="text-xs font-medium text-muted-foreground">{beschriftung.attendees}</dt>
                        <dd className="flex flex-wrap gap-1.5">
                            {event.attendees.map((person) => (
                                <span
                                    key={person.name}
                                    className="rounded-md border px-1.5 py-0.5 text-xs"
                                >
                                    {person.name}
                                    {person.status !== null && person.status !== undefined && person.status !== '' && (
                                        <span className="ml-1 text-muted-foreground">{person.status}</span>
                                    )}
                                </span>
                            ))}
                        </dd>
                    </div>
                )}

                {event.categories !== undefined && event.categories.length > 0 && (
                    <div>
                        <dt className="text-xs font-medium text-muted-foreground">{beschriftung.categories}</dt>
                        <dd className="flex flex-wrap gap-1.5">
                            {event.categories.map((etikett) => (
                                <span key={etikett} className="rounded-md border px-1.5 py-0.5 text-xs">
                                    {etikett}
                                </span>
                            ))}
                        </dd>
                    </div>
                )}

                {extra}
            </dl>

            {event.recurring === true && (
                <p className="text-xs text-muted-foreground">{beschriftung.series}</p>
            )}

            {actions !== undefined && actions !== null && (
                <div className="flex flex-wrap gap-2 border-t pt-3">{actions}</div>
            )}
        </div>
    );
}
