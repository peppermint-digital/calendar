/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { SlotFinder, type FinderDay, type FinderSlot } from '../src/SlotFinder';

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

describe('SlotFinder', () => {
    it('beschriftet den Tag, wie man ihn in Deutschland liest', () => {
        render(<SlotFinder days={[tag()]} onPick={vi.fn()} />);

        // 19.10.2026 ist ein Montag.
        expect(screen.getByText(/Mo.*19\.10\./)).toBeTruthy();
    });

    it('reicht die ISO-Zeiten weiter, nicht die angezeigten', () => {
        const onPick = vi.fn();
        render(<SlotFinder days={[tag()]} onPick={onPick} />);

        fireEvent.click(screen.getByRole('button', { name: '09:00' }));

        // Die Anlegen-Maske braucht den Zeitpunkt mit Datum und Zone — „09:00"
        // allein liesse offen, an welchem Tag.
        expect(onPick).toHaveBeenCalledWith(expect.objectContaining({ startsAt: '2026-10-19T09:00:00+02:00' }));
    });

    it('sperrt einen belegten Platz, statt ihn anklickbar zu lassen', () => {
        const onPick = vi.fn();
        render(
            <SlotFinder
                days={[tag({ slots: [scheibe({ free: false, busyCount: 2, total: 5 })] })]}
                onPick={onPick}
            />,
        );

        const knopf = screen.getByRole('button', { name: /09:00/ });

        expect(knopf.hasAttribute('disabled')).toBe(true);

        fireEvent.click(knopf);
        expect(onPick).not.toHaveBeenCalled();
    });

    it('sagt beim Ueberfahren, WARUM eine Zeit nicht geht', () => {
        render(
            <SlotFinder
                days={[tag({ slots: [scheibe({ free: false, busyCount: 2, total: 5 })] })]}
                onPick={vi.fn()}
            />,
        );

        expect(screen.getByRole('button', { name: /09:00/ }).getAttribute('title')).toBe(
            '2 von 5 haben etwas vor',
        );
    });

    it('zeigt bei mehreren Personen, wie viele noch koennen', () => {
        render(
            <SlotFinder
                days={[tag({ slots: [scheibe({ free: false, busyCount: 2, total: 5 })] })]}
                onPick={vi.fn()}
            />,
        );

        // 5 - 2 = 3 von 5 haetten Zeit.
        expect(screen.getByRole('button', { name: /09:00/ }).textContent).toContain('3/5');
    });

    it('laesst die Zahl weg, wenn man nur sich selbst fragt', () => {
        render(
            <SlotFinder days={[tag({ slots: [scheibe({ free: false, busyCount: 1, total: 1 })] })]} onPick={vi.fn()} />,
        );

        expect(screen.getByRole('button', { name: /09:00/ }).textContent).not.toContain('/1');
    });

    it('sagt es, wenn es nichts zu zeigen gibt', () => {
        render(<SlotFinder days={[]} onPick={vi.fn()} />);

        expect(screen.getByText('Für diesen Zeitraum gibt es nichts zu zeigen.')).toBeTruthy();
        expect(screen.queryByTestId('slot-finder')).toBeNull();
    });
});
