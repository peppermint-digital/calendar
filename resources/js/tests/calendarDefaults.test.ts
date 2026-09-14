import { describe, expect, it } from 'vitest';

import { calendarDefaults } from '../src/calendarDefaults';

describe('calendarDefaults', () => {
    it('nennt deutsche Formate, damit keine Anwendung sie einzeln setzt', () => {
        const d = calendarDefaults();

        expect(d.locale).toBe('de');
        expect(d.firstDay).toBe(1);
        expect(d.weekText).toBe('KW');
        expect(d.weekNumberCalculation).toBe('ISO');
        expect((d.eventTimeFormat as { hour12: boolean }).hour12).toBe(false);
        expect((d.slotLabelFormat as { hour12: boolean }).hour12).toBe(false);
    });

    it('schaltet zwischen Arbeitstag und ganzem Tag', () => {
        expect(calendarDefaults({ fullDay: false }).slotMinTime).toBe('07:00:00');
        expect(calendarDefaults({ fullDay: false }).slotMaxTime).toBe('23:00:00');
        expect(calendarDefaults({ fullDay: true }).slotMinTime).toBe('00:00:00');
        expect(calendarDefaults({ fullDay: true }).slotMaxTime).toBe('24:00:00');
    });

    it('reicht das Wochenende durch', () => {
        expect(calendarDefaults({ weekends: false }).weekends).toBe(false);
        expect(calendarDefaults({ weekends: true }).weekends).toBe(true);
    });

    it('nimmt auf dem Telefon das Ziehen heraus, nicht das Auswaehlen', () => {
        const handy = calendarDefaults({ mobile: true });

        expect(handy.editable).toBe(false);
        expect(handy.eventStartEditable).toBe(false);
        expect(handy.selectable).toBe(true);
        expect(handy.selectMirror).toBe(false);
        expect(handy.allDayText).toBe('Ganz.');
        expect(handy.selectLongPressDelay).toBe(150);
    });

    it('laesst am Rechner alles beweglich', () => {
        const rechner = calendarDefaults();

        expect(rechner.editable).toBe(true);
        expect(rechner.selectMirror).toBe(true);
        expect(rechner.allDayText).toBe('Ganztägig');
    });

    it('haelt das Viertelstundenraster mit stuendlicher Beschriftung', () => {
        // Die Kombination ist der eigentliche Wert: Ein Raster ohne
        // stuendliche Beschriftung pflastert die Zeitachse mit Zahlen zu.
        const d = calendarDefaults();

        expect(d.slotDuration).toBe('00:15:00');
        expect(d.slotLabelInterval).toBe('01:00:00');
    });

    it('ueberlaesst die Leiste der Anwendung', () => {
        // FullCalendars eigene Knoepfe sehen in keiner unserer Oberflaechen
        // aus wie die uebrigen — deshalb zeichnet sie `CalendarToolbar`.
        expect(calendarDefaults().headerToolbar).toBe(false);
    });
});
