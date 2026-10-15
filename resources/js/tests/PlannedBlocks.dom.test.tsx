/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { PlannedBlocks, type PlannedBlock } from '../src/PlannedBlocks';

/**
 * Die geplante Zeit an einem Gegenstand (#7786).
 *
 * Nicht „an einer Aufgabe": Ein Block haengt an etwas — einer Aufgabe, einem
 * Ticket, einem Deal. Der Kalender kennt genau diesen Begriff schon.
 */
const BLOECKE: PlannedBlock[] = [
    { id: 2, date: '2026-11-05', start: '14:00', end: '15:30' },
    { id: 1, date: '2026-11-03', start: '09:00', end: '10:00' },
];

afterEach(cleanup);

describe('die Liste', () => {
    it('ordnet nach Tag und Uhrzeit, nicht nach Eingabereihenfolge', () => {
        render(<PlannedBlocks blocks={BLOECKE} />);

        const zeilen = screen.getAllByRole('listitem').map((z) => z.textContent);

        expect(zeilen[0]).toContain('09:00');
        expect(zeilen[1]).toContain('14:00');
    });

    it('zaehlt die geplante Zeit zusammen', () => {
        // 1 h + 1,5 h
        render(<PlannedBlocks blocks={BLOECKE} />);

        expect(screen.getByText(/2:30 h/)).toBeTruthy();
    });

    it('rechnet einen Block ueber Nacht richtig', () => {
        // 22:00–01:00 ist kein negativer Block, sondern einer von drei Stunden.
        render(<PlannedBlocks blocks={[{ id: 1, date: '2026-11-03', start: '22:00', end: '01:00' }]} />);

        // Auf die SUMME geprüft, nicht auf „3 h" irgendwo: Die Dauer steht auch
        // an der Zeile selbst, und ein Treffer dort sagte nichts über die
        // Rechnung darunter.
        expect(screen.getByText(/3 h geplant/)).toBeTruthy();
    });

    it('sagt es, wenn nichts geplant ist', () => {
        render(<PlannedBlocks blocks={[]} />);

        expect(screen.getByText('Noch keine Zeit eingeplant.')).toBeTruthy();
        // Keine Summe von null Stunden — das waere eine Angabe ohne Aussage.
        expect(screen.queryByText(/h geplant/)).toBeNull();
    });
});

describe('die Knoepfe', () => {
    it('zeigt keinen Entfernen-Knopf ohne den passenden Haken', () => {
        // Eine Ansicht ohne Schreibrecht zeigt die Planung, ohne etwas zu
        // versprechen, das beim Druecken abgewiesen wird.
        render(<PlannedBlocks blocks={BLOECKE} />);

        expect(screen.queryByText('Entfernen')).toBeNull();
        expect(screen.queryByText('Zeit einplanen')).toBeNull();
    });

    it('reicht den gemeinten Block ans Produkt', () => {
        const entfernt = vi.fn();

        render(<PlannedBlocks blocks={BLOECKE} onRemove={entfernt} />);

        // Der zweite Knopf gehoert zum spaeteren Block — nach der SORTIERUNG,
        // nicht nach der Eingabereihenfolge.
        fireEvent.click(screen.getAllByText('Entfernen')[1]!);

        expect(entfernt).toHaveBeenCalledTimes(1);
        expect(entfernt.mock.calls[0][0].id).toBe(2);
    });
});

describe('Produkteigenes', () => {
    it('bekommt seinen Platz je Block', () => {
        // Wer geplant hat, ob verbucht — das fuehrt der Manager, und das Paket
        // muss davon nichts wissen.
        render(
            <PlannedBlocks
                blocks={BLOECKE}
                renderExtra={(block) => <span>verbucht:{block.id}</span>}
            />,
        );

        expect(screen.getByText('verbucht:1')).toBeTruthy();
        expect(screen.getByText('verbucht:2')).toBeTruthy();
    });

    it('liest das Datum als Ortszeit, nicht als UTC', () => {
        // `new Date('2026-11-03')` ist Mitternacht UTC und wird westlich davon
        // zum 2. November — der Block stuende dann am falschen Tag.
        render(<PlannedBlocks blocks={[{ id: 1, date: '2026-11-03', start: '09:00', end: '10:00' }]} />);

        expect(screen.getByRole('listitem').textContent).toContain('03.11');
    });
});
