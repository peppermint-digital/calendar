/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CalendarFilters, isHidden, sourceKey, toggleHidden } from '../src/CalendarFilters';
import type { EventKind } from '../src/types';

afterEach(cleanup);

const ARTEN: EventKind[] = [
    { key: 'termin', label: 'Termin', colour: '#3b82f6' },
    { key: 'aufgabe', label: 'Aufgabe' },
];

const QUELLEN = [{ key: 'manager', label: 'Manager' }];

describe('die Leiste', () => {
    it('zeigt Arten und Quellen zusammen', () => {
        render(<CalendarFilters kinds={ARTEN} sources={QUELLEN} hidden={[]} onToggle={() => {}} />);

        expect(screen.getByRole('button', { name: 'Termin' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Manager' })).toBeTruthy();
    });

    /**
     * Arten und Quellen teilen eine Liste. Ohne Vorzeichen verdeckte eine
     * Quelle `manager` eine Terminart `manager` — und zwar erst bei dem
     * Kunden, der beides hat.
     */
    it('gibt der Quelle ihr Vorzeichen mit', () => {
        const umgeschaltet = vi.fn();

        render(<CalendarFilters sources={QUELLEN} hidden={[]} onToggle={umgeschaltet} />);
        fireEvent.click(screen.getByRole('button', { name: 'Manager' }));

        expect(umgeschaltet).toHaveBeenCalledWith('source:manager', ['source:manager']);
    });

    /** Die neue Liste kommt mit, damit sie nicht viermal ausgerechnet wird. */
    it('reicht die neue Liste mit, nicht nur den Schluessel', () => {
        const umgeschaltet = vi.fn();

        render(<CalendarFilters kinds={ARTEN} hidden={['aufgabe']} onToggle={umgeschaltet} />);
        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe' }));

        expect(umgeschaltet).toHaveBeenCalledWith('aufgabe', []);
    });

    /**
     * `aria-pressed` beschreibt den SICHTBAREN Zustand. Umgekehrt herum liest
     * eine Sprachausgabe das Gegenteil von dem vor, was auf dem Schirm steht.
     */
    it('nennt einen gezeigten Eintrag gedrueckt', () => {
        render(<CalendarFilters kinds={ARTEN} hidden={['aufgabe']} onToggle={() => {}} />);

        expect(screen.getByRole('button', { name: 'Termin' }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByRole('button', { name: 'Aufgabe' }).getAttribute('aria-pressed')).toBe('false');
    });

    /** Der Punkt nennt die Farbe, in der das Raster den Eintrag zeichnet. */
    it('zeigt die Marke nur, wo eine Farbe bekannt ist', () => {
        render(<CalendarFilters kinds={ARTEN} hidden={[]} onToggle={() => {}} />);

        expect(document.querySelectorAll('[data-slot="calendar-filter-dot"]')).toHaveLength(1);
    });

    it('nimmt die Farbe auch aus der Tafel des Produkts', () => {
        render(
            <CalendarFilters
                kinds={[{ key: 'aufgabe', label: 'Aufgabe' }]}
                colourOf={(key) => (key === 'aufgabe' ? '#16a34a' : null)}
                hidden={[]}
                onToggle={() => {}}
            />,
        );

        expect(document.querySelectorAll('[data-slot="calendar-filter-dot"]')).toHaveLength(1);
    });

    /** Produkteigene Gruppen stehen daneben, nicht darin. */
    it('nimmt produkteigene Gruppen daneben auf', () => {
        render(
            <CalendarFilters kinds={ARTEN} hidden={[]} onToggle={() => {}}>
                <button type="button">Nur meine Projekte</button>
            </CalendarFilters>,
        );

        expect(screen.getByRole('button', { name: 'Nur meine Projekte' })).toBeTruthy();
    });

    /** Eine leere Leiste ist schlechter als keine — sie sieht nach Fehler aus. */
    it('zeichnet nichts, wenn es nichts zu filtern gibt', () => {
        render(<CalendarFilters hidden={[]} onToggle={() => {}} />);

        expect(document.querySelector('[data-slot="calendar-filters"]')).toBeNull();
    });
});

/**
 * Die Regel steht im Paket, weil sie zweimal gebraucht wird — beim Zeichnen
 * und beim Aussieben — und beim Aussieben leicht anders gerät. Genau das war
 * im Brain der Fall: Die Leiste kannte `source:`, der Filter für eigene
 * Termine nicht.
 */
describe('die Regel zum Aussieben', () => {
    it('siebt eine eigene Art ueber ihren Schluessel', () => {
        expect(isHidden(['termin'], 'termin')).toBe(true);
        expect(isHidden(['termin'], 'aufgabe')).toBe(false);
    });

    it('siebt einen fremden Termin ueber seine Quelle, nicht ueber seine Art', () => {
        // Die Art heisst drueben vielleicht genauso wie hier — sichtbar ist
        // sie ueber die Quelle, aus der sie kommt.
        expect(isHidden(['source:manager'], 'termin', 'manager')).toBe(true);
        expect(isHidden(['termin'], 'termin', 'manager')).toBe(false);
    });

    it('behandelt eine leere Quelle wie keine', () => {
        expect(isHidden(['termin'], 'termin', '')).toBe(true);
        expect(isHidden(['termin'], 'termin', null)).toBe(true);
    });

    it('schaltet um, ohne die alte Liste anzufassen', () => {
        const vorher = ['a'];

        expect(toggleHidden(vorher, 'b')).toEqual(['a', 'b']);
        expect(toggleHidden(vorher, 'a')).toEqual([]);
        expect(vorher).toEqual(['a']);
    });

    it('schreibt das Vorzeichen an einer Stelle', () => {
        expect(sourceKey('manager')).toBe('source:manager');
    });
});

/**
 * Ein FREMDER Termin hat keine eigene Art — er hängt an seiner Quelle, und
 * die Art drüben ist ein Begriff des anderen Systems. Im Brain stand genau
 * dafür ein zweiter, handgeschriebener Filter (16.10.2026).
 */
it('siebt einen fremden Termin auch ohne Art', () => {
    expect(isHidden(['source:manager'], null, 'manager')).toBe(true);
    expect(isHidden(['source:crm'], null, 'manager')).toBe(false);
    expect(isHidden([], undefined, undefined)).toBe(false);
});
