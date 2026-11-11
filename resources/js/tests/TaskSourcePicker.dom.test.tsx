/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { TaskSourcePicker, type TaskSource } from '../src/TaskSourcePicker';

/**
 * Die Wahl des Aufgabensystems (#9798).
 *
 * Die Entscheidung dahinter, woertlich von Bastian am 11.11.2026: „bei der
 * Aufgabenart muss ich das System ja aber vorher waehlen, oder? Weil sonst
 * kriege ich ja einfach eine unendlich lange Liste mit hunderten Aufgaben. […]
 * Bei Aufgaben ist es wahrscheinlich besser, wenn wir Aufgabesystem und dann
 * Aufgabe machen."
 *
 * Das ist genau umgekehrt zur Terminart — und kein Widerspruch, sondern
 * dieselbe Regel bei anderer Groesse: Zehn Terminarten passen in eine Liste,
 * hunderte Aufgaben nicht.
 */
const SYSTEME: TaskSource[] = [
    { key: 'manager', label: 'Manager', colour: '#2563eb' },
    { key: 'verwaltung', label: 'Verwaltung' },
    { key: 'brain', label: 'AI Brain', colour: null },
];

afterEach(cleanup);

function zeilen(): string[] {
    return Array.from(document.querySelectorAll('[data-slot="task-source-picker"] button')).map(
        (knopf) => (knopf.textContent ?? '').trim(),
    );
}

describe('die Liste', () => {
    it('zeichnet jedes System als eigene Zeile, in der gegebenen Reihenfolge', () => {
        render(<TaskSourcePicker options={SYSTEME} onPick={vi.fn()} />);

        // Hier wird NICHT sortiert — die Reihenfolge entscheidet der Aufrufer,
        // wie bei der Terminart.
        expect(zeilen()).toEqual(['Manager', 'Verwaltung', 'AI Brain']);
    });

    it('gibt das ganze System heraus, nicht nur seinen Schluessel', () => {
        const gewaehlt = vi.fn();

        render(<TaskSourcePicker options={SYSTEME} onPick={gewaehlt} />);

        fireEvent.click(screen.getByRole('button', { name: 'Verwaltung' }));

        expect(gewaehlt).toHaveBeenCalledWith(SYSTEME[1]);
    });

    it('markiert die getroffene Wahl', () => {
        render(<TaskSourcePicker options={SYSTEME} value="brain" onPick={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'AI Brain' }).getAttribute('aria-pressed')).toBe(
            'true',
        );
        expect(screen.getByRole('button', { name: 'Manager' }).getAttribute('aria-pressed')).toBe(
            'false',
        );
    });

    it('traegt den Schluessel als Griff an jeder Zeile', () => {
        const { container } = render(<TaskSourcePicker options={SYSTEME} onPick={vi.fn()} />);

        // Der Griff, an dem die Abnahme im Browser die Zeile findet (#9799).
        expect(
            Array.from(container.querySelectorAll('[data-source]')).map((knopf) =>
                knopf.getAttribute('data-source'),
            ),
        ).toEqual(['manager', 'verwaltung', 'brain']);
    });

    it('sagt es, wenn kein System angesteckt ist', () => {
        render(<TaskSourcePicker options={[]} onPick={vi.fn()} />);

        expect(screen.getByText(/kein Aufgabensystem angesteckt/)).toBeTruthy();
    });

    it('nimmt eigene Woerter an', () => {
        render(
            <TaskSourcePicker
                options={SYSTEME}
                onPick={vi.fn()}
                labels={{ heading: 'Woher?' }}
            />,
        );

        expect(screen.getByText('Woher?')).toBeTruthy();
    });
});

describe('die Farbe des Systems', () => {
    it('steht nur dort, wo es eine gibt', () => {
        const { container } = render(<TaskSourcePicker options={SYSTEME} onPick={vi.fn()} />);

        // Eine Zeile mit Farbe, zwei ohne — `colour` fehlt bei der Verwaltung
        // und ist beim Brain ausdruecklich `null`.
        expect(container.querySelectorAll('[aria-hidden="true"]')).toHaveLength(1);
    });

    /**
     * Vorgelesen waere ein Punkt ohne Aussage, und die Beschriftung daneben
     * sagt dasselbe in Worten.
     */
    it('ist vor Hilfsmitteln verborgen', () => {
        render(<TaskSourcePicker options={[SYSTEME[0]!]} onPick={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'Manager' })).toBeTruthy();
    });
});

/**
 * Was dieses Bauteil ABSICHTLICH nicht hat — und warum das keine Luecke ist.
 *
 * Die Systemliste kommt nicht ueber das Netz: Im Aufgabenpaket ist sie
 * `TaskSourceRegistry::available()`, das niemanden fragt, und im Produkt steht
 * sie schon beim Zeichnen der Seite da. Daraus folgt beides — sie kann nicht
 * nachtraeglich wachsen, und ein System kann hier nicht „nicht antworten".
 *
 * Der Test haelt die VORAUSSETZUNG fest, nicht die Abwesenheit: Wer die Liste
 * eines Tages ueber das Netz holt, bringt diesen Test zu Fall und weiss dann,
 * dass zwei Zusicherungen fehlen.
 */
describe('kein Lade- und kein Ausfallzustand', () => {
    it('kennt weder eine Lade- noch eine Ausfallangabe', () => {
        render(<TaskSourcePicker options={SYSTEME} onPick={vi.fn()} />);

        // Kein role="status": Ein Ausfall entsteht erst beim Ernten der
        // Aufgaben und gehoert an die Stufe danach, wo er auch entsteht.
        expect(screen.queryByRole('status')).toBeNull();
        expect(zeilen()).toHaveLength(3);
    });
});
