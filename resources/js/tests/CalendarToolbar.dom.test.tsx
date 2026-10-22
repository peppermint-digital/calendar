/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CalendarToolbar, type CalendarToolbarButton } from '../src/CalendarToolbar';

afterEach(cleanup);

/**
 * Der Knopf-Schlitz, wie eine Anwendung ihn fuellt. Bewusst so schlicht wie
 * moeglich: Faellt hier etwas auf, liegt es an der Leiste, nicht am Adapter.
 */
const knopf = ({ active, onClick, title, children }: CalendarToolbarButton) => (
    <button key={String(children)} type="button" onClick={onClick} title={title} data-aktiv={active === true}>
        {children}
    </button>
);

describe('CalendarToolbar', () => {
    it('zeigt 24-Stunden- und Wochenend-Schalter, sobald die Seite sie annimmt', () => {
        // Der Kern der Vereinheitlichung: Eine Seite bekommt die Schalter,
        // indem sie einen Handler mitgibt — nicht, indem sie sie nachbaut.
        render(
            <CalendarToolbar
                renderButton={knopf}
                fullDay={false}
                onFullDayChange={() => {}}
                weekends={false}
                onWeekendsChange={() => {}}
            />,
        );

        expect(screen.getByText('24 h')).toBeTruthy();
        expect(screen.getByText('Wochenende')).toBeTruthy();
    });

    it('laesst beide Schalter weg, wenn die Seite sie nicht annimmt', () => {
        // Gegenprobe: Ohne sie zeichnete die Leiste sonst Knoepfe, die nichts
        // tun — schlimmer als keine.
        render(<CalendarToolbar renderButton={knopf} onToday={() => {}} />);

        expect(screen.queryByText('24 h')).toBeNull();
        expect(screen.queryByText('Wochenende')).toBeNull();
    });

    it('kippt den Zustand, statt ihn zu setzen', () => {
        const gerufen = vi.fn();

        render(<CalendarToolbar renderButton={knopf} weekends={true} onWeekendsChange={gerufen} />);

        fireEvent.click(screen.getByText('Wochenende'));

        expect(gerufen).toHaveBeenCalledWith(false);
    });

    it('hebt den aktiven Zustand hervor', () => {
        render(<CalendarToolbar renderButton={knopf} fullDay={true} onFullDayChange={() => {}} />);

        expect(screen.getByText('24 h').getAttribute('data-aktiv')).toBe('true');
    });

    it('zeigt nur die Ansichten, die die Seite anbietet', () => {
        // Das Time-Blocking kennt nur Wochen. Es soll deshalb keine
        // Monatsumschaltung zeigen — und dafuer keine eigene Leiste brauchen.
        render(
            <CalendarToolbar
                renderButton={knopf}
                views={['timeGridWeek', 'dayGridMonth']}
                view="timeGridWeek"
                onViewChange={() => {}}
            />,
        );

        expect(screen.getByText('Woche')).toBeTruthy();
        expect(screen.getByText('Monat')).toBeTruthy();
        expect(screen.queryByText('Tag')).toBeNull();
    });

    it('meldet die gewaehlte Ansicht zurueck', () => {
        const gewaehlt = vi.fn();

        render(
            <CalendarToolbar
                renderButton={knopf}
                views={['timeGridWeek', 'dayGridMonth']}
                view="timeGridWeek"
                onViewChange={gewaehlt}
            />,
        );

        fireEvent.click(screen.getByText('Monat'));

        expect(gewaehlt).toHaveBeenCalledWith('dayGridMonth');
    });

    it('nimmt eigene Beschriftungen an, ohne die uebrigen zu verlieren', () => {
        render(
            <CalendarToolbar
                renderButton={knopf}
                onToday={() => {}}
                fullDay={false}
                onFullDayChange={() => {}}
                labels={{ today: 'Jetzt' }}
            />,
        );

        expect(screen.getByText('Jetzt')).toBeTruthy();
        expect(screen.getByText('24 h')).toBeTruthy();
    });

    it('stellt den Zusatz der Seite neben die Schalter', () => {
        render(
            <CalendarToolbar renderButton={knopf} weekends onWeekendsChange={() => {}}>
                <span>Eigenes</span>
            </CalendarToolbar>,
        );

        expect(screen.getByText('Eigenes')).toBeTruthy();
    });
});

describe('die Ansichts-Knoepfe', () => {
    /**
     * Der Schluessel gehoert dem Paket: Die Liste baut diese Leiste. Vorher kam
     * er — wenn ueberhaupt — aus der Beschriftung, die das Produkt in
     * `renderButton` setzt. Wo das Produkt ihn vergass, meldete React „unique
     * key prop" (gesehen im Manager, 15.10.2026) und durfte beim Wechsel der
     * Ansichtsliste Knoepfe verwechseln.
     */
    it('kommt ohne Schluessel aus dem Produkt aus', () => {
        const meldungen: unknown[] = [];
        const echt = console.error;
        console.error = (...args: unknown[]) => meldungen.push(args[0]);

        try {
            render(
                <CalendarToolbar
                    views={['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek']}
                    view="timeGridWeek"
                    onViewChange={() => {}}
                    // Bewusst OHNE `key` — genau so macht es ein Produkt, das
                    // nicht weiss, dass es einen setzen muesste.
                    renderButton={({ onClick, children }) => <button onClick={onClick}>{children}</button>}
                />,
            );
        } finally {
            console.error = echt;
        }

        expect(meldungen.filter((m) => String(m).includes('unique "key"'))).toHaveLength(0);
    });
});

/**
 * Die Leiste am Telefon (#8599, 22.10.2026).
 *
 * Gemessen bei 390 px Bildschirmbreite: Die Leiste ist dort 358 px breit, ihr
 * Inhalt summiert sich aber auf 688 px — Navigation 259, Ansichten 258,
 * Schalter 171. Das ergibt drei Reihen und 112 px, bevor der erste Termin zu
 * sehen ist. Zwei Reihen zusammenzulegen geht nicht: 258 + 171 passen
 * ebenfalls nicht nebeneinander.
 *
 * Also wandert das SELTENERE in einen Ueberlauf. „Monat / Woche / Tag" waehlt
 * man staendig, „24 h" und „Wochenende" stellt man einmal ein.
 */
describe('die Leiste auf schmalen Bildschirmen', () => {
    const breiteSetzen = (px: number) => {
        Object.defineProperty(window, 'innerWidth', { value: px, writable: true, configurable: true });
        fireEvent(window, new Event('resize'));
    };

    afterEach(() => breiteSetzen(1024));

    const leiste = () =>
        render(
            <CalendarToolbar
                renderButton={knopf}
                views={['dayGridMonth', 'timeGridWeek']}
                view="timeGridWeek"
                onViewChange={() => {}}
                onToday={() => {}}
                fullDay={false}
                onFullDayChange={() => {}}
                weekends
                onWeekendsChange={() => {}}
            />,
        );

    it('raeumt die Ansichts-Schalter in den Ueberlauf', () => {
        breiteSetzen(390);
        leiste();

        expect(screen.getByLabelText('Weitere Ansichtsoptionen')).toBeTruthy();

        // Die Ansichten bleiben sichtbar — sie sind das Haeufige.
        expect(screen.getByRole('button', { name: 'Monat' })).toBeTruthy();
    });

    it('laesst sie breit in der Leiste stehen', () => {
        breiteSetzen(1024);
        leiste();

        expect(screen.queryByLabelText('Weitere Ansichtsoptionen')).toBeNull();
        expect(screen.getByRole('button', { name: '24 h' })).toBeTruthy();
    });

    /**
     * Der eigentliche Waechter. Der naheliegende Weg waere, beide Fassungen zu
     * zeichnen und eine per CSS auszublenden — dann staenden zwei bedienbare
     * Knoepfe desselben Namens im Baum. Ein Screenreader liest sie doppelt,
     * und jede Suche nach „dem 24-h-Knopf" findet zwei. Das braeche auch die
     * Tests der vier Produkte, die dieses Paket einbinden.
     */
    it('zeigt jeden Schalter genau einmal, egal wie breit', () => {
        breiteSetzen(390);
        leiste();
        expect(screen.getAllByRole('button', { name: '24 h' })).toHaveLength(1);

        cleanup();

        breiteSetzen(1024);
        leiste();
        expect(screen.getAllByRole('button', { name: '24 h' })).toHaveLength(1);
    });
});
