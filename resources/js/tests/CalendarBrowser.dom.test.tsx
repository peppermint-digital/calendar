/**
 * @vitest-environment jsdom
 */
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { CalendarBrowser } from '../src/CalendarBrowser';

afterEach(cleanup);

/** Den Rahmen und seine Teile ueber `data-slot` greifen, nicht ueber Klassen. */
const teil = (name: string) => document.querySelector(`[data-slot="${name}"]`);

describe('die Slots', () => {
    it('zeigt alles, was das Produkt hineingibt', () => {
        render(
            <CalendarBrowser
                title={<h1>Kalender</h1>}
                actions={<button>Termin erstellen</button>}
                notice={<p>Zwei Eintraege liegen uebereinander.</p>}
                toolbar={<div>Monat Woche Tag</div>}
                grid={<div>das Raster</div>}
                aside={<ul><li>noch einzuplanen</li></ul>}
            />,
        );

        expect(screen.getByRole('heading', { name: 'Kalender' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Termin erstellen' })).toBeTruthy();
        expect(screen.getByText('Zwei Eintraege liegen uebereinander.')).toBeTruthy();
        expect(screen.getByText('Monat Woche Tag')).toBeTruthy();
        expect(screen.getByText('das Raster')).toBeTruthy();
        expect(screen.getByText('noch einzuplanen')).toBeTruthy();
    });

    /**
     * Ein Rahmen, der leere Huellen baut, verschenkt `space-y` an Luft: Zwischen
     * Kopf und Raster stehen dann Abstaende fuer etwas, das nicht da ist.
     */
    it('baut keine Huelle fuer einen Slot, den keiner gefuellt hat', () => {
        render(<CalendarBrowser grid={<div>das Raster</div>} />);

        expect(teil('calendar-head')).toBeNull();
        expect(teil('calendar-aside')).toBeNull();
        expect(teil('calendar-card')).toBeTruthy();
    });

    it('baut den Kopf schon, wenn nur die Bedienelemente da sind', () => {
        render(<CalendarBrowser actions={<button>Heute</button>} grid={<div />} />);

        expect(teil('calendar-head')).toBeTruthy();
    });
});

describe('die Spalte rechts', () => {
    /**
     * Ohne Beiwagen bleibt der Rahmen einspaltig. Stand `xl:flex-row` immer da,
     * wuerde die Karte auf breiten Schirmen nur noch so breit wie ihr Inhalt —
     * ein Raster ohne Nachbarn soll die ganze Breite nehmen.
     */
    it('schaltet nur mit Beiwagen auf zwei Spalten', () => {
        const { unmount } = render(<CalendarBrowser grid={<div />} />);
        expect(teil('calendar-body')?.className).not.toContain('xl:flex-row');
        unmount();

        render(<CalendarBrowser grid={<div />} aside={<div />} />);
        expect(teil('calendar-body')?.className).toContain('xl:flex-row');
    });

    it('nimmt die Breite als fertige Klasse, nicht als Zahl', () => {
        render(<CalendarBrowser grid={<div />} aside={<div />} asideWidth="xl:w-96" />);

        expect(teil('calendar-aside')?.className).toContain('xl:w-96');
    });
});

describe('die Hoehe', () => {
    /**
     * Die Vollhoehe gilt erst ab `lg`. Darunter umbricht die Filterleiste so
     * stark, dass fuer das Raster nichts uebrigbleibt — dort scrollt die Seite.
     */
    it('klemmt sich ab lg auf die Fensterhoehe', () => {
        render(<CalendarBrowser grid={<div />} />);

        expect(teil('calendar-browser')?.className).toContain('lg:h-[calc(100dvh-6rem)]');
        expect(teil('calendar-browser')?.className).toContain('lg:overflow-hidden');
    });

    /**
     * `lg:flex-1` ist der Teil, an dem es haengt: Ohne ihn bekommt die Karte
     * keine Hoehe zugeteilt und das Raster darin faellt auf null zusammen —
     * ohne Fehler, es ist einfach weg.
     */
    it('teilt der Karte und dem Raster die Resthoehe zu', () => {
        render(<CalendarBrowser grid={<div />} />);

        expect(teil('calendar-body')?.className).toContain('lg:flex-1');
        expect(teil('calendar-card')?.className).toContain('lg:flex-1');
        expect(teil('calendar-card')?.className).toContain('lg:min-h-0');
    });

    it('laesst die Seite scrollen, wenn das Produkt die Vollhoehe abschaltet', () => {
        render(<CalendarBrowser grid={<div />} fullHeight={false} />);

        const rahmen = teil('calendar-browser')?.className ?? '';
        expect(rahmen).not.toContain('100dvh');
        expect(rahmen).not.toContain('lg:overflow-hidden');
        expect(teil('calendar-card')?.className).not.toContain('lg:flex-1');
    });

    it('nimmt eine eigene Hoehe, wenn die Kopfzeile des Systems hoeher ist', () => {
        render(<CalendarBrowser grid={<div />} heightClass="lg:h-[calc(100dvh-8rem)] lg:overflow-hidden" />);

        expect(teil('calendar-browser')?.className).toContain('lg:h-[calc(100dvh-8rem)]');
        expect(teil('calendar-browser')?.className).not.toContain('6rem');
    });
});

describe('die Karte', () => {
    /**
     * Auf dem Telefon ist eine Karte, die so breit ist wie der Schirm, keine
     * Karte mehr — nur ein Strich aussen herum. Der Manager macht das seit je
     * so, und es ist der Grund, warum sein Kalender auf dem Telefon nicht
     * eingerahmt wirkt.
     */
    it('legt Rand, Grund und Schatten auf dem Telefon ab', () => {
        render(<CalendarBrowser grid={<div />} />);

        const karte = teil('calendar-card')?.className ?? '';
        expect(karte).toContain('max-md:border-0');
        expect(karte).toContain('max-md:bg-transparent');
        expect(karte).toContain('max-md:shadow-none');
    });

    it('setzt keine leeren oder undefinierten Klassen', () => {
        render(<CalendarBrowser grid={<div />} />);

        for (const name of ['calendar-browser', 'calendar-body', 'calendar-card']) {
            const klasse = teil(name)?.className ?? '';
            expect(klasse).not.toContain('undefined');
            expect(klasse).not.toContain('false');
            expect(klasse).not.toMatch(/\s{2,}/);
        }
    });
});
