/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { SlotFinderScreen } from '../src/SlotFinderScreen';
import type { FinderDay, FinderSlot } from '../src/SlotFinder';

afterEach(cleanup);

const scheibe = (over: Partial<FinderSlot> = {}): FinderSlot => ({
    start: '09:00',
    end: '09:30',
    startsAt: '2026-10-19T09:00:00+02:00',
    endsAt: '2026-10-19T09:30:00+02:00',
    free: true,
    busyCount: 0,
    total: 1,
    ...over,
});

const tag = (over: Partial<FinderDay> = {}): FinderDay => ({
    date: '2026-10-19',
    weekend: false,
    today: false,
    slots: [scheibe()],
    ...over,
});

/** Der Knopf des Produkts — hier der schlichteste, den es gibt. */
const knopf = ({ onClick, title, children, active }: any) => (
    <button type="button" onClick={onClick} title={title} data-aktiv={active ? 'ja' : 'nein'}>
        {children}
    </button>
);

const personen = [
    { id: 1, name: 'Anna', selected: true },
    { id: 2, name: 'Bert', selected: false },
];

const einstellung = { from: '2026-10-19', days: 7, duration: 30 };

function aufbauen(over: Record<string, unknown> = {}) {
    const onNavigate = vi.fn();
    const onPick = vi.fn();

    render(
        <SlotFinderScreen
            days={[tag()]}
            attendees={personen}
            settings={einstellung}
            renderButton={knopf}
            onNavigate={onNavigate}
            onPick={onPick}
            {...over}
        />,
    );

    return { onNavigate, onPick };
}

describe('SlotFinderScreen', () => {
    it('nennt im Untertitel die Anzahl der Gewaehlten', () => {
        aufbauen();

        // Eine Person gewaehlt → die persoenliche Fassung.
        expect(screen.getByText('Wann hast du Zeit?')).toBeTruthy();

        cleanup();
        aufbauen({
            attendees: [
                { id: 1, name: 'Anna', selected: true },
                { id: 2, name: 'Bert', selected: true },
            ],
        });

        expect(screen.getByText('Wann haben alle 2 Zeit?')).toBeTruthy();
    });

    /**
     * Der Grund, warum `verschobenerTag` nicht ueber `toISOString()` geht:
     * Das rechnet nach UTC um, und ein Anker vom 1. Maerz wuerde in Berlin zum
     * 28. Februar.
     */
    it('schiebt den Zeitraum um seine eigene Laenge, nicht um eine Woche', () => {
        const { onNavigate } = aufbauen({ settings: { from: '2026-10-19', days: 14, duration: 30 } });

        fireEvent.click(screen.getByTitle('Weiter'));
        expect(onNavigate).toHaveBeenCalledWith({ von: '2026-11-02' });

        fireEvent.click(screen.getByTitle('Zurück'));
        expect(onNavigate).toHaveBeenCalledWith({ von: '2026-10-05' });
    });

    it('rechnet ueber eine Monatsgrenze richtig', () => {
        const { onNavigate } = aufbauen({ settings: { from: '2026-02-26', days: 7, duration: 30 } });

        fireEvent.click(screen.getByTitle('Weiter'));
        expect(onNavigate).toHaveBeenCalledWith({ von: '2026-03-05' });
    });

    it('hebt die eingestellte Dauer und Zeitraumlaenge hervor', () => {
        aufbauen();

        expect(screen.getByText('30 min').getAttribute('data-aktiv')).toBe('ja');
        expect(screen.getByText('60 min').getAttribute('data-aktiv')).toBe('nein');
        expect(screen.getByText('7 Tage').getAttribute('data-aktiv')).toBe('ja');
        expect(screen.getByText('14 Tage').getAttribute('data-aktiv')).toBe('nein');
    });

    /**
     * Das Produkt bekommt nur die AENDERUNG und legt Einstellung und Auswahl
     * selbst darunter. Wer stattdessen den ganzen Zustand uebergaebe, muesste
     * ihn an jeder Aufrufstelle vollstaendig zusammentragen.
     */
    it('schickt beim Abwaehlen die verbleibende Auswahl', () => {
        const { onNavigate } = aufbauen();

        fireEvent.click(screen.getByText('Anna'));
        expect(onNavigate).toHaveBeenCalledWith({ users: [] });
    });

    it('schickt beim Hinzuwaehlen die erweiterte Auswahl', () => {
        const { onNavigate } = aufbauen();

        fireEvent.click(screen.getByText('Bert'));
        expect(onNavigate).toHaveBeenCalledWith({ users: [1, 2] });
    });

    it('gibt den angeklickten freien Platz weiter', () => {
        const { onPick } = aufbauen();

        fireEvent.click(screen.getByText('09:00'));
        expect(onPick).toHaveBeenCalledWith(expect.objectContaining({ startsAt: '2026-10-19T09:00:00+02:00' }));
    });

    it('nimmt den Weg zurueck vom Produkt', () => {
        aufbauen({ back: <a href="/calendar">Zurück zum Kalender</a> });

        expect(screen.getByText('Zurück zum Kalender').getAttribute('href')).toBe('/calendar');
    });

    /**
     * Das Raster sass in den drei Produktseiten in einer zweiten Karte, obwohl
     * `CalendarBrowser` schon eine mitbringt — doppelter Rand, doppelte
     * Polsterung. Dieser Test haelt fest, dass es jetzt genau eine ist.
     */
    it('setzt das Raster in genau EINE Karte', () => {
        const { container } = render(
            <SlotFinderScreen
                days={[tag()]}
                attendees={personen}
                settings={einstellung}
                renderButton={knopf}
                onNavigate={vi.fn()}
                onPick={vi.fn()}
            />,
        );

        expect(container.querySelectorAll('[data-slot="calendar-card"]').length).toBe(1);
    });

    it('laesst das Produkt die Beschriftung austauschen', () => {
        aufbauen({ labels: { title: 'Wann passt es?', span: '{n} Tage voraus' } });

        expect(screen.getByText('Wann passt es?')).toBeTruthy();
        expect(screen.getByText('7 Tage voraus')).toBeTruthy();
    });
});
