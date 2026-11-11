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
    /**
     * Aus welchem SYSTEM der Gegenstand kommt. Leer oder fehlend = dem eigenen.
     *
     * Dieselbe Art von Grenze wie `subjectType`, eine Ebene hoeher: „Aufgabe 7"
     * im Manager und „Aufgabe 7" in der Verwaltung sind zwei Posten. Solange
     * eine Liste nur das eigene System zeigt, aendert die Angabe nichts — und
     * genau deshalb darf sie fehlen: Die Produkte, die heute aus dieser Liste
     * ins Raster ziehen, ziehen ohne eine Zeile Aenderung weiter.
     *
     * Der Name ist der des Aufgabenpakets (`ForeignTask::$sourceKey`), damit das
     * Produkt durchreicht und nichts uebersetzt.
     */
    sourceKey?: string;
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

/**
 * Das System der Karte, am DOM.
 *
 * Es steht neben der Kennung und nicht in ihr: Eine zusammengesetzte Kennung
 * („manager:7") muesste beim Ablegen wieder zerlegt werden, und wer sie
 * weiterreicht, schickt dem Zielsystem eine Kennung, die es nicht kennt.
 */
export const PLANNABLE_SOURCE_ATTRIBUTE = 'data-plannable-source';

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
                const source = card.getAttribute(PLANNABLE_SOURCE_ATTRIBUTE) ?? '';
                // Kennung UND System: Zwei Systeme duerfen dieselbe Kennung
                // fuehren, und dann traegt die abgelegte Karte sonst den Titel
                // und die Dauer des falschen Postens.
                const item = items.find(
                    (entry) => String(entry.id) === id && (entry.sourceKey ?? '') === source,
                );

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
                          key={`${item.sourceKey ?? ''}:${item.subjectType ?? ''}:${item.id}`}
                          {...{ [PLANNABLE_ATTRIBUTE]: String(item.id) }}
                          {...{ [PLANNABLE_SOURCE_ATTRIBUTE]: item.sourceKey ?? '' }}
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
