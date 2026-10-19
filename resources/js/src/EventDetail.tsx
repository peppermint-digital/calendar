import type { ReactNode } from 'react';

import { CategoryPicker, type PickableCategory } from './CategoryPicker';

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
    /**
     * Ein privater Termin — nur für die eigene Person sichtbar.
     *
     * Als eigenes Feld und nicht aus `kindLabel` abgelesen: „privat" ist eine
     * Sichtbarkeit, keine Terminart. Ein geschäftlicher Termin kann privat
     * gestellt sein, und ein Geburtstag ist es immer.
     */
    private?: boolean;
    attendees?: { name: string; status?: string | null }[];
    categories?: string[];
    /** Die Slugs dazu — nötig, um umzuschalten. Ein Etikett ist kein Schlüssel. */
    categorySlugs?: string[];
};

export type EventDetailLabels = {
    when: string;
    where: string;
    notes: string;
    attendees: string;
    categories: string;
    series: string;
    allDay: string;
    duration: string;
    privateHint: string;
    /** 135 → „2 Std. 15 Min." */
    formatDuration: (minutes: number) => string;
};

/** 135 → „2 Std. 15 Min."; 60 → „1 Std."; 45 → „45 Min." */
function deutscheDauer(minuten: number): string {
    const stunden = Math.floor(minuten / 60);
    const rest = minuten % 60;

    if (stunden === 0) return `${rest} Min.`;
    if (rest === 0) return stunden === 1 ? '1 Std.' : `${stunden} Std.`;

    return `${stunden} Std. ${rest} Min.`;
}

const DETAIL_DEFAULTS: EventDetailLabels = {
    when: 'Wann',
    where: 'Wo',
    notes: 'Notiz',
    attendees: 'Teilnehmer',
    categories: 'Kategorien',
    series: 'Teil einer Serie',
    allDay: 'ganztägig',
    duration: 'Dauer',
    privateHint: 'Privat',
    formatDuration: deutscheDauer,
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
    /**
     * Die Kategorien, die DIESE Terminart anbietet — vom Produkt gereicht,
     * weil nur es weiss, wer fragt.
     *
     * Zusammen mit `onCategoriesChange` wird die Kategorienzeile waehlbar.
     * Ohne beides bleibt sie eine Anzeige, und ohne Eintraege verschwindet
     * sie: Ein leerer Auswahlbereich ist schlimmer als keiner.
     */
    availableCategories?: PickableCategory[];
    /**
     * Wird mit der NEUEN vollstaendigen Liste gerufen, nicht mit der
     * Aenderung. Eine Maske schickt den Zustand, den sie anzeigt — wer
     * „hinzugefuegt"/„entfernt" schickt, muss beim Empfaenger zusammenrechnen,
     * und zwei Rechnungen laufen auseinander.
     */
    onCategoriesChange?: (slugs: string[]) => void;
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
    availableCategories,
    onCategoriesChange,
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

    /**
     * Wie lange der Termin dauert, in Minuten — oder null.
     *
     * Bei einem ganztägigen Termin steht die Dauer NICHT in Minuten: „1440
     * Min." ist keine Auskunft, und bei mehreren Tagen erst recht nicht. Dort
     * sagt der Zeitraum oben schon alles.
     *
     * Die Dauer stand bis zum 19.10.2026 nur in der Einzelansicht des
     * Managers. Sie ist die zweite Frage nach „wann" — und sie aus zwei
     * Uhrzeiten im Kopf zu rechnen ist genau die Arbeit, die eine Maske
     * abnehmen soll.
     */
    const dauerMinuten = (): number | null => {
        if (ganztags) return null;
        if (event.start === null || event.start === undefined || event.start === '') return null;
        if (event.end === null || event.end === undefined || event.end === '') return null;
        if (event.endDate !== null && event.endDate !== undefined && event.endDate !== '' && event.endDate !== event.date) return null;

        const [vonStunde, vonMinute] = event.start.split(':').map(Number);
        const [bisStunde, bisMinute] = event.end.split(':').map(Number);

        if ([vonStunde, vonMinute, bisStunde, bisMinute].some((zahl) => zahl === undefined || Number.isNaN(zahl))) {
            return null;
        }

        const minuten = (bisStunde! * 60 + bisMinute!) - (vonStunde! * 60 + vonMinute!);

        // Ein Termin über Mitternacht hinaus käme negativ heraus. Lieber keine
        // Angabe als eine falsche.
        return minuten > 0 ? minuten : null;
    };

    const dauer = dauerMinuten();

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

                {(event.private === true ||
                    (event.kindLabel !== null && event.kindLabel !== undefined && event.kindLabel !== '')) && (
                    <p className="text-xs text-muted-foreground">
                        {event.kindLabel}
                        {event.private === true && (
                            <span data-testid="event-detail-private">
                                {event.kindLabel !== null && event.kindLabel !== undefined && event.kindLabel !== ''
                                    ? ' · '
                                    : ''}
                                {beschriftung.privateHint}
                            </span>
                        )}
                    </p>
                )}
            </div>

            <dl className="space-y-3 text-sm">
                <div>
                    <dt className="text-xs font-medium text-muted-foreground">{beschriftung.when}</dt>
                    <dd>{zeitraum()}</dd>
                    {dauer !== null && (
                        <dd className="text-muted-foreground text-xs" data-testid="event-detail-duration">
                            {beschriftung.duration}: {beschriftung.formatDuration(dauer)}
                        </dd>
                    )}
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

                {/*
                    Kategorien: anzeigen, und bei `onCategoriesChange` auch
                    waehlbar.

                    Waehlbar auch dann, wenn der Termin noch KEINE trägt — das
                    ist der ganze Punkt. Gemessen im Manager am 19.10.2026:
                    483 von 1211 Terminen sind Zeitblöcke an einer Aufgabe, und
                    keiner konnte eine Kategorie tragen. Ein Block entsteht
                    durch Ziehen, es gibt also keinen Dialog, in dem man sie
                    mitwählen könnte; hier ist die Stelle.
                */}
                {onCategoriesChange !== undefined && availableCategories !== undefined
                    ? availableCategories.length > 0 && (
                        <div>
                            <dt className="text-xs font-medium text-muted-foreground">{beschriftung.categories}</dt>
                            <dd>
                                <CategoryPicker
                                    available={availableCategories}
                                    selected={event.categorySlugs ?? []}
                                    onChange={onCategoriesChange}
                                />
                            </dd>
                        </div>
                    )
                    : event.categories !== undefined && event.categories.length > 0 && (
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
