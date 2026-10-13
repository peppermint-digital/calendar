/**
 * @vitest-environment jsdom
 */
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { PlannableList, PLANNABLE_ATTRIBUTE, type PlannableEntry } from '../src/PlannableList';

const zerstoeren = vi.fn();
const gebaut: Array<{ element: HTMLElement; options: Record<string, unknown> }> = [];

vi.mock('@fullcalendar/interaction', () => ({
    Draggable: class {
        constructor(element: HTMLElement, options: Record<string, unknown>) {
            gebaut.push({ element, options });
        }

        destroy() {
            zerstoeren();
        }
    },
}));

afterEach(() => {
    cleanup();
    gebaut.length = 0;
    zerstoeren.mockClear();
});

const aufgaben: PlannableEntry[] = [
    { id: 7, title: 'Angebot schreiben', minutes: 90 },
    { id: 8, title: 'Serverwartung', recurring: true, subjectType: 'habit' },
];

const karte = (item: PlannableEntry) => <span>{item.title}</span>;

describe('PlannableList', () => {
    it('haengt an jede Karte, was der Ablegevorgang liest', () => {
        const { container } = render(<PlannableList items={aufgaben} renderItem={karte} />);

        const erste = container.querySelector(`[${PLANNABLE_ATTRIBUTE}="7"]`);

        expect(erste).not.toBeNull();
        expect(erste?.getAttribute('data-duration-minutes')).toBe('90');
        expect(erste?.getAttribute('data-plannable-title')).toBe('Angebot schreiben');
        expect(screen.getByText('Serverwartung')).toBeTruthy();
    });

    it('nimmt eine Stunde an, wo keine Dauer steht', () => {
        const { container } = render(<PlannableList items={[{ id: 1, title: 'Ohne Dauer' }]} renderItem={karte} />);

        expect(container.querySelector(`[${PLANNABLE_ATTRIBUTE}="1"]`)?.getAttribute('data-duration-minutes')).toBe('60');
    });

    it('baut den Draggable nach einer Aenderung der Liste NEU', () => {
        // Der Fallstrick, den Manager und AI Brain unabhaengig voneinander
        // entdecken mussten: Ein einmal gebauter Draggable kennt die spaeter
        // gezeichneten Karten nicht. Sie sehen ziehbar aus und sind es nicht.
        const { rerender } = render(<PlannableList items={aufgaben} renderItem={karte} />);

        expect(gebaut).toHaveLength(1);

        rerender(<PlannableList items={[{ id: 9, title: 'Neu dazugekommen' }]} renderItem={karte} />);

        expect(gebaut).toHaveLength(2);
        expect(zerstoeren).toHaveBeenCalledTimes(1);
    });

    it('liefert beim Ablegen Titel und Dauer des gezogenen Gegenstands', () => {
        const { container } = render(<PlannableList items={aufgaben} renderItem={karte} />);

        const karteImDom = container.querySelector(`[${PLANNABLE_ATTRIBUTE}="7"]`) as HTMLElement;
        const daten = (gebaut[0].options.eventData as (el: HTMLElement) => Record<string, unknown>)(karteImDom);

        expect(daten.title).toBe('Angebot schreiben');
        expect(daten.duration).toEqual({ minutes: 90 });
    });

    it('reicht durch, was die Anwendung zusaetzlich am Termin braucht', () => {
        const { container } = render(
            <PlannableList items={aufgaben} renderItem={karte} eventData={(item) => ({ kind: 'verwaltung_ticket', ticketId: item.id })} />,
        );

        const karteImDom = container.querySelector(`[${PLANNABLE_ATTRIBUTE}="7"]`) as HTMLElement;
        const daten = (gebaut[0].options.eventData as (el: HTMLElement) => Record<string, unknown>)(karteImDom);

        expect(daten.kind).toBe('verwaltung_ticket');
        expect(daten.ticketId).toBe(7);
    });

    it('zeigt den Leertext, wenn nichts zu planen ist', () => {
        render(<PlannableList items={[]} renderItem={karte} empty={<p>Alles verplant</p>} />);

        expect(screen.getByText('Alles verplant')).toBeTruthy();
    });
});
