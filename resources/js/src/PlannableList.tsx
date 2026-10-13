import { Draggable } from '@fullcalendar/interaction';
import { useEffect, useRef, type ReactNode } from 'react';

/**
 * Die Liste der Dinge, die noch in den Kalender geplant werden koennen.
 *
 * Zwei Systeme hatten sie unabhaengig voneinander gebaut — Manager und AI
 * Brain —, und beide mussten denselben Fallstrick entdecken: Nach jedem
 * Neuzeichnen der Liste (Suche, Reiterwechsel, Wochenwechsel) muss der
 * `Draggable` NEU gebaut werden, sonst sind die frischen Karten nicht ziehbar.
 * Das ist der eigentliche Grund fuer dieses Bauteil.
 *
 * ## Was hier liegt und was nicht
 *
 * Hier: die Verdrahtung mit FullCalendar — der Container, die Datenattribute,
 * die der Ablegevorgang liest, und der Neuaufbau bei Aenderungen.
 *
 * Nicht hier: wie eine Karte aussieht. Das kommt ueber `renderItem`, aus
 * demselben Grund wie `renderButton` an der Werkzeugleiste: Ein zweiter
 * Knopf-Stil mitten in einer fertigen Oberflaeche faellt sofort auf.
 */
export interface PlannableEntry {
    id: number | string;
    title: string;
    /** Vorgabedauer beim Ablegen. Ohne Angabe eine Stunde. */
    minutes?: number;
    /**
     * Wiederkehrend: Ein solcher Gegenstand kommt in jedes Fenster zurueck, in
     * dem er noch nicht geplant ist. Das Paket entscheidet das serverseitig
     * (`TimeBlocking\Board`); hier steht es nur zur Anzeige.
     */
    recurring?: boolean;
    /**
     * Welche Sorte Gegenstand — Aufgabe, Ticket, Gewohnheit. Noetig, sobald
     * eine Liste mehrere Sorten zeigt: Kennungen sind nur INNERHALB ihrer
     * Sorte eindeutig.
     */
    subjectType?: string;
    /** Was die Anwendung sonst noch an der Karte braucht. */
    meta?: Record<string, unknown>;
}

export interface PlannableListProps {
    items: PlannableEntry[];
    /** Wie eine Karte aussieht. Die Umhuellung samt Datenattributen kommt von hier. */
    renderItem: (item: PlannableEntry) => ReactNode;
    /** Steht da, wenn nichts zu planen ist. */
    empty?: ReactNode;
    className?: string;
    /**
     * Zusaetzliche Angaben fuer den abgelegten Termin — etwa die Terminart.
     * FullCalendar reicht alles durch, was hier zurueckkommt.
     */
    eventData?: (item: PlannableEntry) => Record<string, unknown>;
}

/** Das Attribut, an dem der Ablegevorgang eine Karte erkennt. */
export const PLANNABLE_ATTRIBUTE = 'data-plannable-id';

export function PlannableList({ items, renderItem, empty, className, eventData }: PlannableListProps) {
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const element = container.current;

        if (!element) {
            return;
        }

        // Gegen das AKTUELLE DOM gebaut, und bei jeder Aenderung der Liste neu.
        // Ein einmal gebauter Draggable kennt die spaeter gezeichneten Karten
        // nicht — sie sehen ziehbar aus und sind es nicht.
        const draggable = new Draggable(element, {
            itemSelector: `[${PLANNABLE_ATTRIBUTE}]`,
            eventData(card: HTMLElement) {
                const id = card.getAttribute(PLANNABLE_ATTRIBUTE) ?? '';
                const item = items.find((entry) => String(entry.id) === id);

                return {
                    title: item?.title ?? card.dataset.plannableTitle ?? '',
                    duration: { minutes: item?.minutes ?? 60 },
                    ...(item && eventData ? eventData(item) : {}),
                };
            },
        });

        return () => draggable.destroy();
    }, [items, eventData]);

    return (
        <div ref={container} className={className} data-plannable-list="">
            {items.length === 0
                ? empty
                : items.map((item) => (
                      <div
                          key={`${item.subjectType ?? ''}:${item.id}`}
                          {...{ [PLANNABLE_ATTRIBUTE]: String(item.id) }}
                          data-plannable-title={item.title}
                          data-plannable-type={item.subjectType ?? ''}
                          data-duration-minutes={item.minutes ?? 60}
                      >
                          {renderItem(item)}
                      </div>
                  ))}
        </div>
    );
}
