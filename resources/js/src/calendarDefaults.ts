import type { CalendarOptions } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import timeGridPlugin from '@fullcalendar/timegrid';

export type CalendarDefaultsOptions = {
    /** 0–24 Uhr statt des Arbeitstags (7–23). */
    fullDay?: boolean;
    weekends?: boolean;
    /**
     * Telefon-Anpassungen: kuerzeres „Ganztaegig", kein Ziehen, laengeres
     * Antippen. Der Peppermint Manager braucht sie, der Brain-Kalender nicht —
     * deshalb ein Schalter und keine zweite Fassung.
     */
    mobile?: boolean;
};

/**
 * Die Einstellungen, die in jedem unserer Kalender gleich sein sollen.
 *
 * Sie sind der Teil, der auseinanderlaeuft: ein deutsches Zeitformat hier, ein
 * Viertelstundenraster dort, Kalenderwochen nur an einer Stelle. Keine davon
 * ist einzeln der Rede wert, und genau deshalb merkt niemand, wenn eine
 * Verbesserung nur in einer von drei Anwendungen ankommt.
 *
 * Bewusst eine Funktion und keine Komponente: Der Peppermint Manager baut sein
 * `CalendarOptions`-Objekt selbst und mischt viel Eigenes hinein — Mobilgeraete,
 * eigene Termindarstellung, eigene Menues. Eine Komponente muesste er
 * entweder ganz uebernehmen oder gar nicht; diese Funktion kann er
 * ausbreiten und ueberschreiben, wo er es braucht.
 *
 * Die Anwendungen sind deutsch, also sind es auch die Formate: 24-Stunden-
 * Zeiten ohne AM/PM, Wochenbeginn am Montag, Kalenderwochen als „KW".
 */
export function calendarDefaults({
    fullDay = false,
    weekends = true,
    mobile = false,
}: CalendarDefaultsOptions = {}): CalendarOptions {
    return {
        plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],

        // Die Leiste zeichnet `CalendarToolbar`, nicht FullCalendar: Deren
        // Knoepfe sehen in keiner unserer Oberflaechen aus wie die uebrigen.
        headerToolbar: false,

        locale: 'de',
        firstDay: 1,
        weekNumbers: true,
        weekNumberCalculation: 'ISO',
        weekText: 'KW',
        allDayText: mobile ? 'Ganz.' : 'Ganztägig',
        noEventsText: 'Keine Termine in diesem Zeitraum',
        buttonText: { today: 'Heute' },

        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },

        // Viertelstundenraster mit stuendlicher Beschriftung: fein genug, um
        // einen Termin auf Viertel zu setzen, ohne die Zeitachse mit Zahlen
        // zuzupflastern.
        slotDuration: '00:15:00',
        slotLabelInterval: '01:00:00',
        slotMinTime: fullDay ? '00:00:00' : '07:00:00',
        slotMaxTime: fullDay ? '24:00:00' : '23:00:00',

        dayHeaderFormat: { weekday: 'short', day: 'numeric' },
        weekends,
        nowIndicator: true,
        expandRows: true,

        // Ziehen ist auf dem Telefon aus, Antippen und Auswaehlen bleiben.
        editable: ! mobile,
        eventStartEditable: ! mobile,
        eventDurationEditable: ! mobile,
        eventResizableFromStart: ! mobile,
        selectable: true,
        selectMirror: ! mobile,
        selectLongPressDelay: mobile ? 150 : 1000,
        eventLongPressDelay: mobile ? 500 : 1000,

        dayMaxEvents: 3,
        moreLinkClick: 'popover',
        fixedWeekCount: false,

        businessHours: { daysOfWeek: [1, 2, 3, 4, 5], startTime: '08:00', endTime: '18:00' },
    };
}
