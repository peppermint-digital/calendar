/**
 * @vitest-environment jsdom
 */
import { cleanup, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * FullCalendar wird nachgebildet, nicht gerendert.
 *
 * Geprueft wird, WAS das Raster an FullCalendar durchreicht — nicht, wie
 * FullCalendar das zeichnet. Ein echtes Rendern braeuchte Layout-Masse, die
 * jsdom nicht hat, und pruefte dann fremden Code.
 */
let letzteProps: Record<string, unknown> | null = null;

vi.mock('@fullcalendar/react', () => ({
    default: (props: Record<string, unknown>) => {
        letzteProps = props;

        return null;
    },
}));

import { CalendarGrid } from '../src/CalendarGrid';

const zuletztGerendert = () => letzteProps;

/** Die Fensterbreite setzen, wie jsdom sie meldet. */
function setzeFensterbreite(breite: number): void {
    Object.defineProperty(window, 'innerWidth', { value: breite, configurable: true, writable: true });
    window.dispatchEvent(new Event('resize'));
}

beforeEach(() => {
    letzteProps = null;
    setzeFensterbreite(1280);
});

afterEach(cleanup);

describe('die Hoehe', () => {
    /**
     * `height: '100%'` setzt voraus, dass der Container eine DEFINITE Hoehe
     * hat. Fehlt die, loest es gegen null auf und das Raster verschwindet —
     * ohne Fehler, ohne Hinweis, einfach weg.
     *
     * Der Manager fuehrt diese Logik seit je in seinem eigenen Raster, weil
     * der Kalender-Reiter seiner Aufgabenansicht genau dort hineinlief
     * (gemessen 15.10.2026, #7846).
     */
    it('nimmt die uebergebene Hoehe, wenn keine feste gesetzt ist', () => {
        render(<CalendarGrid entries={[]} view="timeGridWeek" fullDay={false} weekends height="600px" />);

        expect(zuletztGerendert()?.height).toBe('600px');
    });

    it('nimmt die feste Hoehe, sobald eine gesetzt ist', () => {
        render(
            <CalendarGrid entries={[]} view="timeGridWeek" fullDay={false} weekends height="100%" fixedHeight={480} />,
        );

        // Nicht `100%`: Der Container hat hier keine definite Hoehe, und das
        // Raster waere unsichtbar.
        expect(zuletztGerendert()?.height).toBe(480);
    });

    it('fuellt ab lg den Container und faellt darunter auf die feste Hoehe', () => {
        const aufbau = (
            <CalendarGrid
                entries={[]}
                view="timeGridWeek"
                fullDay={false}
                weekends
                height="100%"
                fixedHeight={480}
                fillsContainerFromLg
            />
        );

        setzeFensterbreite(1280);
        const { unmount } = render(aufbau);
        expect(zuletztGerendert()?.height).toBe('100%');
        unmount();

        // Gestapelt gibt es keine definite Container-Hoehe mehr.
        setzeFensterbreite(900);
        render(aufbau);
        expect(zuletztGerendert()?.height).toBe(480);
    });

    it('laesst die feste Hoehe unangetastet, wenn sie allein steht', () => {
        // Ohne `fillsContainerFromLg` gilt sie auf jeder Breite — eine
        // Umgebung, die NIE eine definite Container-Hoehe hat.
        setzeFensterbreite(1920);
        render(
            <CalendarGrid entries={[]} view="timeGridWeek" fullDay={false} weekends height="100%" fixedHeight={320} />,
        );

        expect(zuletztGerendert()?.height).toBe(320);
    });
});

describe('der erste Tag', () => {
    /**
     * Der Manager haelt den sichtbaren Zeitraum in der Adresse. Ohne
     * `initialDate` faellt ein neu geladener Tab auf heute zurueck — und wer
     * einen Link auf eine bestimmte Woche verschickt, verschickt „heute".
     */
    it('reicht den ersten Tag durch', () => {
        render(<CalendarGrid entries={[]} view="timeGridWeek" fullDay={false} weekends initialDate="2026-03-09" />);

        expect(zuletztGerendert()?.initialDate).toBe('2026-03-09');
    });

    it('laesst ihn weg, wenn keiner genannt ist — dann gilt heute', () => {
        render(<CalendarGrid entries={[]} view="timeGridWeek" fullDay={false} weekends />);

        expect(zuletztGerendert()?.initialDate).toBeUndefined();
    });
});

describe('was ein einzelner Eintrag mitbringt', () => {
    const eintrag = {
        id: '1',
        title: 'Abgabe',
        start: '2026-03-09T09:00:00',
        allDay: false,
        payload: null,
    };

    const ersterTermin = () => (zuletztGerendert()?.events as Array<Record<string, unknown>>)[0];

    /**
     * Eine Faelligkeit ist ein Zeitpunkt, kein Zeitraum: verschiebbar, aber
     * „zwei Stunden lang faellig" ergibt keinen Sinn.
     */
    it('trennt Verschieben von Dehnen', () => {
        render(
            <CalendarGrid
                entries={[{ ...eintrag, editable: true, durationEditable: false }]}
                view="timeGridWeek"
                fullDay={false}
                weekends
            />,
        );

        expect(ersterTermin().editable).toBe(true);
        expect(ersterTermin().durationEditable).toBe(false);
    });

    /**
     * Ohne Angabe darf der Schluessel NICHT gesetzt werden: Ein ausdrueckliches
     * `undefined` ueberschreibt die globale Option — und auf dem Telefon ist
     * Ziehen bewusst aus.
     */
    it('schweigt ueber die Dauer, wenn der Eintrag nichts sagt', () => {
        render(
            <CalendarGrid entries={[{ ...eintrag, editable: true }]} view="timeGridWeek" fullDay={false} weekends />,
        );

        expect('durationEditable' in ersterTermin()).toBe(false);
    });

    it('reicht eine Textfarbe durch', () => {
        render(
            <CalendarGrid
                entries={[{ ...eintrag, editable: false, colour: '#1e3a8a', textColour: '#ffffff' }]}
                view="timeGridWeek"
                fullDay={false}
                weekends
            />,
        );

        expect(ersterTermin().textColor).toBe('#ffffff');
    });
});

describe('ein Eintrag wird gezogen', () => {
    const termin = (ende: Date | null, allDay = false) => ({
        event: { start: new Date('2026-03-09T09:00:00'), end: ende, allDay, extendedProps: { entryPayload: { id: 7 } } },
        revert: vi.fn(),
    });

    const zieheMit = (props: Record<string, unknown>) => {
        render(<CalendarGrid entries={[]} view="timeGridWeek" fullDay={false} weekends {...props} />);

        return zuletztGerendert()!;
    };

    it('meldet Anfang, Ende und die Nutzlast', () => {
        const bewegt = vi.fn();
        const fc = zieheMit({ onEntryMove: bewegt });
        const arg = termin(new Date('2026-03-09T10:00:00'));

        (fc.eventDrop as (a: unknown) => void)(arg);

        expect(bewegt).toHaveBeenCalledWith({ id: 7 }, arg.event.start, arg.event.end, expect.anything());
    });

    /**
     * Vorher wurde auf Anfang UND Ende geprueft. Ein Zeitpunkt — eine
     * Faelligkeit, eine Deadline — hat kein Ende und fiel dadurch stumm durch:
     * Man zog ihn, er blieb liegen, und niemand erfuhr warum.
     */
    it('meldet auch einen Eintrag ohne Ende', () => {
        const bewegt = vi.fn();
        const fc = zieheMit({ onEntryMove: bewegt });

        (fc.eventDrop as (a: unknown) => void)(termin(null));

        expect(bewegt).toHaveBeenCalled();
        expect(bewegt.mock.calls[0][3].end).toBeNull();
        // Die dritte Stelle kann kein `null` ausdruecken und zeigt auf den Anfang.
        expect(bewegt.mock.calls[0][2]).toEqual(bewegt.mock.calls[0][1]);
    });

    /**
     * Ohne `allDay` macht man aus jedem Ganztagstermin beim ersten Verschieben
     * einen Zeittermin — genau dann, wenn jemand ihn sowieso anfasst.
     */
    it('sagt, ob der Eintrag in der Ganztagszeile gelandet ist', () => {
        const bewegt = vi.fn();
        const fc = zieheMit({ onEntryMove: bewegt });

        (fc.eventDrop as (a: unknown) => void)(termin(null, true));

        expect(bewegt.mock.calls[0][3].allDay).toBe(true);
    });

    /**
     * Ohne Zuruecksprigen bleibt eine abgelehnte Verschiebung sichtbar stehen,
     * waehrend der Server die alte Zeit haelt — das sieht aus wie Erfolg.
     */
    it('reicht das Zuruecksprigen durch', () => {
        const bewegt = vi.fn();
        const fc = zieheMit({ onEntryMove: bewegt });
        const arg = termin(new Date('2026-03-09T10:00:00'));

        (fc.eventDrop as (a: unknown) => void)(arg);
        bewegt.mock.calls[0][3].revert();

        expect(arg.revert).toHaveBeenCalled();
    });
});

describe('ein Eintrag wird gedehnt', () => {
    const arg = () => ({
        event: {
            start: new Date('2026-03-09T09:00:00'),
            end: new Date('2026-03-09T11:00:00'),
            allDay: false,
            extendedProps: { entryPayload: { id: 7 } },
        },
        revert: vi.fn(),
    });

    /**
     * Verschieben aendert den Anfang, Dehnen nur das Ende. Wer beides an
     * dieselbe Route schickt, verschiebt beim Dehnen ungewollt mit.
     */
    it('geht an den eigenen Handler, wenn es einen gibt', () => {
        const bewegt = vi.fn();
        const gedehnt = vi.fn();
        render(
            <CalendarGrid
                entries={[]}
                view="timeGridWeek"
                fullDay={false}
                weekends
                onEntryMove={bewegt}
                onEntryResize={gedehnt}
            />,
        );

        (zuletztGerendert()!.eventResize as (a: unknown) => void)(arg());

        expect(gedehnt).toHaveBeenCalled();
        expect(bewegt).not.toHaveBeenCalled();
    });

    /** Ohne eigenen Handler bleibt es, wie es war, bevor es ihn gab. */
    it('faellt sonst auf das Verschieben zurueck', () => {
        const bewegt = vi.fn();
        render(
            <CalendarGrid entries={[]} view="timeGridWeek" fullDay={false} weekends onEntryMove={bewegt} />,
        );

        (zuletztGerendert()!.eventResize as (a: unknown) => void)(arg());

        expect(bewegt).toHaveBeenCalled();
    });
});
