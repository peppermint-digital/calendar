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
