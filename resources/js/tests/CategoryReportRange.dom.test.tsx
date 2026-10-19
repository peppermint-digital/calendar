/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CategoryReportRange } from '../src/CategoryReportRange';

afterEach(cleanup);

/** Dasselbe Muster wie in den Produkten: Der Knopf kommt von aussen. */
const knopf = ({ onClick, title, children }: { onClick: () => void; title?: string; children: React.ReactNode }) => (
    <button type="button" onClick={onClick} title={title}>
        {children}
    </button>
);

function aufbauen(onApply = vi.fn(), start = '2026-10-01', end = '2026-10-31') {
    render(<CategoryReportRange start={start} end={end} onApply={onApply} renderButton={knopf} />);

    return onApply;
}

describe('CategoryReportRange', () => {
    it('nennt den Monat, in dem der Zeitraum beginnt', () => {
        aufbauen();

        expect(screen.getByText('Oktober 2026')).toBeTruthy();
    });

    it('meldet erst beim Anwenden, nicht bei jedem Tastendruck', () => {
        const onApply = aufbauen();
        const felder = screen.getAllByRole('textbox');

        fireEvent.change(felder[0]!, { target: { value: '05.10.2026' } });

        // Noch nichts gemeldet: Ein halbfertiger Zeitraum gehoert nicht nach
        // draussen, sonst laedt das Produkt eine Auswertung fuer ein Datum,
        // das niemand gemeint hat.
        expect(onApply).not.toHaveBeenCalled();

        fireEvent.click(screen.getByText('Anwenden'));

        expect(onApply).toHaveBeenCalledWith('2026-10-05', '2026-10-31');
    });

    it('springt einen Monat zurueck und nimmt den ganzen Monat', () => {
        const onApply = aufbauen();

        fireEvent.click(screen.getByTitle('Vorheriger Monat'));

        expect(onApply).toHaveBeenCalledWith('2026-09-01', '2026-09-30');
    });

    it('trifft den letzten Tag auch im Februar', () => {
        const onApply = aufbauen(vi.fn(), '2026-03-01', '2026-03-31');

        fireEvent.click(screen.getByTitle('Vorheriger Monat'));

        expect(onApply).toHaveBeenCalledWith('2026-02-01', '2026-02-28');
    });

    it('trifft ihn auch im Schaltjahr', () => {
        const onApply = aufbauen(vi.fn(), '2028-03-01', '2028-03-31');

        fireEvent.click(screen.getByTitle('Vorheriger Monat'));

        expect(onApply).toHaveBeenCalledWith('2028-02-01', '2028-02-29');
    });

    it('springt ueber den Jahreswechsel', () => {
        const onApply = aufbauen(vi.fn(), '2026-12-01', '2026-12-31');

        fireEvent.click(screen.getByTitle('Nächster Monat'));

        expect(onApply).toHaveBeenCalledWith('2027-01-01', '2027-01-31');
    });

    it('zeigt den gesprungenen Monat auch an, nicht nur gemeldet', () => {
        aufbauen();

        fireEvent.click(screen.getByTitle('Vorheriger Monat'));

        expect(screen.getByText('September 2026')).toBeTruthy();
    });
});
