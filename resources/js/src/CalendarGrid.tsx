import type { CalendarApi, DateSelectArg, EventClickArg, EventContentArg, EventDropArg } from '@fullcalendar/core';
import type { DateClickArg, DropArg, EventResizeDoneArg } from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import { forwardRef, useImperativeHandle, useRef, type ReactNode } from 'react';

import { calendarDefaults } from './calendarDefaults';

export type CalendarViewName = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek';

/** Ein Eintrag im Raster. Farbe und Menue haengen an ihm. */
export type CalendarEntry = {
    id: string;
    title: string;
    start: string;
    end?: string;
    allDay: boolean;
    colour?: string;
    editable: boolean;
    /** Was hinter dem Eintrag steckt; kommt in den Handlern unveraendert zurueck. */
    payload: unknown;
    /** Zeilen fuer das Faehnchen beim Ueberfahren. */
    tooltip?: string[];
    url?: string;
};

export type CalendarGridHandle = {
    api: () => CalendarApi | undefined;
};

export type CalendarGridProps = {
    entries: CalendarEntry[];
    view: CalendarViewName;
    /** 0–24 Uhr statt des Arbeitstags. */
    fullDay: boolean;
    weekends: boolean;
    /** Nimmt Karten aus einer Liste daneben an (Time-Blocking). */
    acceptsExternalDrops?: boolean;
    height?: string;
    /** Erster Wochentag nach ISO-Zaehlung. 1 = Montag. */
    firstDay?: number;
    locale?: string;
    /** Telefon-Anpassungen durchreichen — siehe `calendarDefaults`. */
    mobile?: boolean;
    onSelect?: (start: Date, end: Date, allDay: boolean) => void;
    onDateClick?: (date: Date, allDay: boolean) => void;
    onEntryClick?: (payload: unknown) => void;
    onEntryMove?: (payload: unknown, start: Date, end: Date) => void;
    onExternalDrop?: (element: HTMLElement, date: Date) => void;
    onDatesSet?: (title: string, start: Date, end: Date) => void;
    /** Inhalt des Rechtsklick-Menues. Null heisst: dieser Eintrag hat keines. */
    renderMenu?: (payload: unknown) => ReactNode | null;
    /**
     * Legt das Menue um den Eintrag.
     *
     * Das Paket bringt keine Bedienelemente mit — welche Menuekomponente eine
     * Anwendung benutzt, ist ihre Entscheidung und soll es bleiben. Ohne diesen
     * Schlitz muesste hier eine bestimmte Bibliothek stehen, und das Raster
     * waere nur noch fuer Anwendungen zu haben, die dieselbe verwenden.
     */
    menuWrapper?: (body: ReactNode, menu: ReactNode) => ReactNode;
};

/**
 * Das Kalenderraster — einmal, fuer jede Seite und jede Anwendung, die eines
 * zeigt.
 *
 * Vorher gab es es dreimal: im Brain-Kalender, im Time-Blocking und im
 * Peppermint Manager. Solche Fassungen laufen nicht mit einem Knall
 * auseinander, sondern indem eine Verbesserung nur an einer Stelle ankommt —
 * ein deutsches Zeitformat hier, ein Viertelstundenraster dort.
 *
 * Was eine Anwendung eigenes braucht, haengt an benannten Schlitzen
 * (`renderMenu`, `menuWrapper`), nicht an einer zweiten Fassung.
 */
export const CalendarGrid = forwardRef<CalendarGridHandle, CalendarGridProps>(function CalendarGrid(
    {
        entries,
        view,
        fullDay,
        weekends,
        acceptsExternalDrops = false,
        height = 'auto',
        firstDay = 1,
        locale = 'de',
        mobile = false,
        onSelect,
        onDateClick,
        onEntryClick,
        onEntryMove,
        onExternalDrop,
        onDatesSet,
        renderMenu,
        menuWrapper,
    },
    ref,
) {
    const calendarRef = useRef<FullCalendar | null>(null);

    useImperativeHandle(ref, () => ({ api: () => calendarRef.current?.getApi() }), []);

    function payloadOf(arg: { event: { extendedProps: Record<string, unknown> } }): unknown {
        return arg.event.extendedProps.entryPayload;
    }

    return (
        <FullCalendar
            ref={calendarRef}
            {...calendarDefaults({ fullDay, weekends, mobile })}
            initialView={view}
            height={height}
            locale={locale}
            firstDay={firstDay}
            droppable={acceptsExternalDrops}
            events={entries.map((entry) => ({
                id: entry.id,
                title: entry.title,
                start: entry.start,
                end: entry.end,
                allDay: entry.allDay,
                backgroundColor: entry.colour,
                borderColor: entry.colour,
                editable: entry.editable,

                // `url` nur setzen, wenn es eines gibt. FullCalendar wandelt
                // den Wert in eine Zeichenkette um — ein ausdruecklich gesetztes
                // `undefined` wird dabei zum Text „undefined", und der Klick auf
                // einen ganz normalen Termin landet auf der Seite /undefined.
                ...(entry.url ? { url: entry.url } : {}),
                extendedProps: { entryPayload: entry.payload, tooltip: entry.tooltip },
            }))}
            eventDidMount={(arg) => {
                const lines = arg.event.extendedProps.tooltip as string[] | undefined;

                // Das Faehnchen des Browsers, nicht ein eigenes: Es ueberlebt das
                // Neuzeichnen, funktioniert in jeder Ansicht und kostet nichts.
                if (lines && lines.length > 0) {
                    arg.el.title = lines.join('\n');
                }
            }}
            eventContent={(arg: EventContentArg) => {
                const payload = payloadOf(arg);

                // Beim Aufziehen zeichnet FullCalendar einen Spiegel-Termin —
                // eine Vorschau ohne unsere Nutzlast. Wer sie ungeprueft ans
                // Menue weiterreicht, bringt das Raster mitten in der Bewegung
                // zum Absturz, und die Seite friert ein.
                const menu = payload === undefined ? null : (renderMenu?.(payload) ?? null);

                // Der Klick haengt am Inhalt, nicht an FullCalendars
                // `eventClick`: Sobald ein Rechtsklick-Menue darueberliegt,
                // erreicht der linke Klick das Raster nicht mehr — er endet im
                // Ausloeser des Menues, und das Oeffnen eines Termins passiert
                // stillschweigend nicht mehr.
                const body = (
                    <div
                        className="h-full w-full cursor-pointer overflow-hidden px-1 py-0.5 text-xs leading-tight"
                        onClick={() => payload !== undefined && onEntryClick?.(payload)}
                    >
                        {arg.timeText && <div className="opacity-80">{arg.timeText}</div>}
                        <div className="truncate font-medium">{arg.event.title}</div>
                    </div>
                );

                if (menu === null || menuWrapper === undefined) {
                    return body;
                }

                return menuWrapper(body, menu);
            }}
            datesSet={(info) => onDatesSet?.(info.view.title, info.start, info.end)}
            select={(info: DateSelectArg) => onSelect?.(info.start, info.end, info.allDay)}
            dateClick={(info: DateClickArg) => onDateClick?.(info.date, info.allDay)}
            eventClick={(info: EventClickArg) => {
                // Greift in der Listenansicht, die keinen eigenen Inhalt
                // zeichnet. Im Raster hat der Inhalt seinen eigenen Handler;
                // dort kommt dieser hier nicht an.
                const payload = payloadOf(info);

                if (payload !== undefined) {
                    onEntryClick?.(payload);
                }
            }}
            eventDrop={(info: EventDropArg) => {
                if (info.event.start && info.event.end) {
                    onEntryMove?.(payloadOf(info), info.event.start, info.event.end);
                }
            }}
            eventResize={(info: EventResizeDoneArg) => {
                if (info.event.start && info.event.end) {
                    onEntryMove?.(payloadOf(info), info.event.start, info.event.end);
                }
            }}
            drop={(info: DropArg) => onExternalDrop?.(info.draggedEl, info.date)}
        />
    );
});
