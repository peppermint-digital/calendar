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
