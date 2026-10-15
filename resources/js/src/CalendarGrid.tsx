import type { CalendarApi, DateSelectArg, EventClickArg, EventContentArg, EventDropArg } from '@fullcalendar/core';
import type { DateClickArg, DropArg, EventResizeDoneArg } from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import { forwardRef, type ReactNode, useEffect, useImperativeHandle, useRef, useState } from 'react';

import { calendarDefaults } from './calendarDefaults';

export type CalendarViewName = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek';

/** Ein Eintrag im Raster. Farbe und Menue haengen an ihm. */
/**
 * Was ausser der neuen Zeit noch zu einem Zug gehoert.
 *
 * Als eigenes Objekt und nicht als weitere Stellen in der Handler-Signatur:
 * Die drei Angaben werden selten alle gebraucht, und drei weitere Positionen
 * machen jeden Aufruf unleserlich.
 */
export type EntryChange = {
    /**
     * Liegt der Eintrag nach dem Zug in der Ganztagszeile?
     *
     * Wer das nicht mitschickt, macht aus jedem Ganztagstermin beim ersten
     * Verschieben einen Zeittermin — und zwar genau dann, wenn jemand ihn
     * sowieso gerade anfasst.
     */
    allDay: boolean;
    /**
     * Das Ende, so wie es ist: `null`, wenn der Eintrag keins hat.
     *
     * Ein Zeitpunkt — eine Faelligkeit, eine Deadline — traegt keins. Die
     * dritte Stelle des Handlers kann das nicht ausdruecken und zeigt dann auf
     * den Anfang; hier steht die Wahrheit.
     */
    end: Date | null;
    /**
     * Den Eintrag zuruecksprigen lassen: Der Server hat abgelehnt.
     *
     * Gebraucht ueberall dort, wo die Aenderung per API geht statt ueber einen
     * Seitenwechsel. Ohne diesen Griff bleibt eine abgelehnte Verschiebung
     * sichtbar stehen, waehrend der Server die alte Zeit haelt — und das ist
     * schlimmer als eine Fehlermeldung, weil es wie Erfolg aussieht.
     *
     * Wer ueber Inertia neu laedt, braucht ihn nicht: Dort kommen die Eintraege
     * ohnehin frisch.
     */
    revert: () => void;
};

export type CalendarEntry = {
    id: string;
    title: string;
    start: string;
    end?: string;
    allDay: boolean;
    colour?: string;
    editable: boolean;
    /**
     * Darf die DAUER geaendert werden — also am Rand gezogen?
     *
     * Ohne Angabe gilt `editable`: Was sich verschieben laesst, laesst sich
     * auch dehnen. Getrennt gebraucht wird es fuer Eintraege, die ein
     * Zeitpunkt sind und kein Zeitraum: eine Faelligkeit, eine Deadline. Die
     * darf man woandershin schieben, aber „zwei Stunden lang faellig" ergibt
     * keinen Sinn.
     */
    durationEditable?: boolean;
    /**
     * Textfarbe im Termin. Ohne Angabe entscheidet FullCalendar.
     *
     * Gebraucht dort, wo `colour` aus einer gepflegten Palette kommt und
     * durchgehend dunkel genug fuer weisse Schrift ist — sonst wechselt die
     * Lesbarkeit mit der Farbe.
     */
    textColour?: string;
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
    /**
     * Auf welchen Tag das Raster beim ersten Zeichnen springt (`YYYY-MM-DD`).
     *
     * Ohne Angabe: heute. Gebraucht wird es dort, wo der sichtbare Zeitraum in
     * der Adresse steht — dann bringt ein neu geladener Tab denselben Ausschnitt
     * mit, statt auf heute zurueckzufallen.
     *
     * Wirkt nur beim ERSTEN Zeichnen, wie FullCalendars `initialDate`. Spaeter
     * springt man ueber `ref.api()?.gotoDate(...)`.
     */
    initialDate?: string;
    /** 0–24 Uhr statt des Arbeitstags. */
    fullDay: boolean;
    weekends: boolean;
    /** Nimmt Karten aus einer Liste daneben an (Time-Blocking). */
    acceptsExternalDrops?: boolean;
    /**
     * Die Hoehe des Rasters. `auto` waechst mit dem Inhalt, `100%` fuellt den
     * Container — aber nur, wenn der eine DEFINITE Hoehe hat.
     */
    height?: string;
    /**
     * Feste Hoehe in Pixeln, wenn der Container keine definite hat.
     *
     * `height: '100%'` loest dann gegen null auf, und das Raster VERSCHWINDET —
     * ohne Fehler, ohne Hinweis, einfach weg. Das trifft jede Umgebung, in der
     * der Kalender in einer Karte oder einem Reiter sitzt.
     *
     * Gemessen am 15.10.2026: Der Manager fuehrt diese Logik seit je in seinem
     * eigenen Raster (`fixedHeight`), weil der Kalender-Reiter seiner
     * Aufgabenansicht genau in diese Falle lief.
     */
    fixedHeight?: number | null;
    /**
     * Ab `lg` die Container-Hoehe ausfuellen, darunter `fixedHeight` benutzen.
     *
     * Fuer Umgebungen, in denen die Hoehe erst ab der Zweispaltigkeit
     * feststeht — eine Spalte, die ihre Hoehe von der Nachbarspalte bekommt.
     * Gestapelt (unter `lg`) gibt es dort keine definite Hoehe.
     *
     * Die Grenze ist Tailwinds `lg` (1024px) und muss mit den `lg:`-Klassen des
     * umgebenden Aufbaus zusammenpassen.
     */
    fillsContainerFromLg?: boolean;
    /** Erster Wochentag nach ISO-Zaehlung. 1 = Montag. */
    firstDay?: number;
    locale?: string;
    /** Telefon-Anpassungen durchreichen — siehe `calendarDefaults`. */
    mobile?: boolean;
    onSelect?: (start: Date, end: Date, allDay: boolean) => void;
    onDateClick?: (date: Date, allDay: boolean) => void;
    onEntryClick?: (payload: unknown) => void;
    /**
     * Der Eintrag wurde an eine andere Stelle gezogen.
     *
     * Die dritte Stelle ist das Ende — hat der Eintrag keins, steht dort der
     * Anfang. Wer den Unterschied braucht, liest `change.end`.
     */
    onEntryMove?: (payload: unknown, start: Date, end: Date, change: EntryChange) => void;
    /**
     * Der Eintrag wurde am Rand laenger oder kuerzer gezogen.
     *
     * Ohne diesen Handler faellt das Dehnen auf {@link onEntryMove} zurueck —
     * so war es, bevor es ihn gab. Getrennt gebraucht wird er, weil die beiden
     * verschiedene Dinge sind: Verschieben aendert den Anfang, Dehnen nur das
     * Ende. Wer beides an dieselbe Route schickt, verschiebt beim Dehnen
     * ungewollt mit.
     */
    onEntryResize?: (payload: unknown, start: Date, end: Date, change: EntryChange) => void;
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
        initialDate,
        fullDay,
        weekends,
        acceptsExternalDrops = false,
        height = 'auto',
        fixedHeight = null,
        fillsContainerFromLg = false,
        firstDay = 1,
        locale = 'de',
        mobile = false,
        onSelect,
        onDateClick,
        onEntryClick,
        onEntryMove,
        onEntryResize,
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

    /**
     * Einen Zug melden — gemeinsam fuer Verschieben und Dehnen.
     *
     * Nur `start` wird verlangt, nicht auch `end`. Vorher wurde auf beides
     * geprueft, und ein Eintrag OHNE Ende — eine Faelligkeit, eine Deadline —
     * fiel dadurch stumm durch: Man zog ihn, er blieb liegen, und niemand
     * erfuhr warum.
     */
    function melden(
        info: EventDropArg | EventResizeDoneArg,
        handler: CalendarGridProps['onEntryMove'],
    ): void {
        const start = info.event.start;

        if (! start || ! handler) {
            return;
        }

        handler(payloadOf(info), start, info.event.end ?? start, {
            allDay: info.event.allDay,
            end: info.event.end,
            revert: () => info.revert(),
        });
    }

    /**
     * Welche Hoehe gilt gerade?
     *
     * Ohne `fixedHeight` die uebergebene — der einfache Fall. Mit, haengt es
     * von der Breite ab: `fillsContainerFromLg` fuellt ab 1024px den
     * Container und faellt darunter auf die feste Hoehe zurueck, weil es
     * gestapelt keine definite Container-Hoehe gibt.
     *
     * Die Grenze ist Tailwinds `lg` und muss mit den `lg:`-Klassen des
     * umgebenden Aufbaus zusammenpassen.
     */
    const [breit, setBreit] = useState(() =>
        typeof window === 'undefined' ? true : window.innerWidth >= 1024,
    );

    useEffect(() => {
        if (fixedHeight === null || ! fillsContainerFromLg) {
            return;
        }

        const messen = () => setBreit(window.innerWidth >= 1024);
        window.addEventListener('resize', messen);
        messen();

        return () => window.removeEventListener('resize', messen);
    }, [fillsContainerFromLg, fixedHeight]);

    const gewaehlteHoehe =
        fixedHeight === null ? height : fillsContainerFromLg && breit ? height : fixedHeight;

    return (
        <FullCalendar
            ref={calendarRef}
            {...calendarDefaults({ fullDay, weekends, mobile })}
            initialView={view}
            initialDate={initialDate}
            height={gewaehlteHoehe}
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
                textColor: entry.textColour,
                editable: entry.editable,

                // Nur setzen, wenn der Eintrag es sagt: Ein ausdruecklich
                // uebergebenes `undefined` ueberschreibt sonst die globale
                // Option — und auf dem Telefon ist Ziehen bewusst aus.
                ...(entry.durationEditable === undefined
                    ? {}
                    : { durationEditable: entry.durationEditable }),

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
            eventDrop={(info: EventDropArg) => melden(info, onEntryMove)}
            eventResize={(info: EventResizeDoneArg) => melden(info, onEntryResize ?? onEntryMove)}
            drop={(info: DropArg) => onExternalDrop?.(info.draggedEl, info.date)}
        />
    );
});
