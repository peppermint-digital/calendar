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

/**
 * „Aus diesem System geht gerade nichts" — die Absage VOR den vier Schritten
 * (#9901).
 *
 * In der Maske erscheinen vier Aufgabensysteme, einplanen laesst sich aber nur
 * aus dem eigenen: Eine fremde Aufgabe hat im Manager kein lokales Modell. Ohne
 * Kennzeichnung geht man vier Stufen und bekommt am Ende eine Absage — und eine
 * Maske, die hingehen laesst und dann absagt, ist schlechter als eine, die es
 * vorher sagt.
 *
 * Der Satz ist eine LOKALE Angabe des Aufrufers, keine Abfrage: Er steht beim
 * Zeichnen schon da, wie die Liste selbst.
 */
describe('„hier laesst sich nichts einplanen"', () => {
    const MIT_GRUND: TaskSource[] = [
        { key: 'manager', label: 'Manager' },
        {
            key: 'verwaltung',
            label: 'Verwaltung',
            notPlannableReason: 'Aus der Verwaltung laesst sich hier noch nichts einplanen.',
        },
    ];

    function gruende(): string[] {
        return Array.from(
            document.querySelectorAll('[data-slot="task-source-not-plannable"]'),
        ).map((zeile) => (zeile.textContent ?? '').trim());
    }

    it('zeigt den Grund als zweite Zeile unter dem Namen', () => {
        const { container } = render(<TaskSourcePicker options={MIT_GRUND} onPick={vi.fn()} />);

        expect(gruende()).toEqual(['Aus der Verwaltung laesst sich hier noch nichts einplanen.']);

        // Unter dem Namen und INNERHALB der Zeile, zu der er gehoert — nicht
        // als eigener Hinweis am Fuss der Liste, der zu nichts zeigt.
        const zeile = container.querySelector('[data-source="verwaltung"]');
        expect(zeile?.querySelector('[data-slot="task-source-not-plannable"]')).toBeTruthy();
    });

    it('laesst ein System ohne Grund aussehen wie heute', () => {
        const { container } = render(<TaskSourcePicker options={SYSTEME} onPick={vi.fn()} />);

        // Keines der drei traegt einen Grund — also keine zweite Zeile
        // irgendwo, und die Zeilen sagen weiter genau ihren Namen.
        expect(container.querySelectorAll('[data-slot="task-source-not-plannable"]')).toHaveLength(
            0,
        );
        expect(zeilen()).toEqual(['Manager', 'Verwaltung', 'AI Brain']);
    });

    it('nimmt `null` wie „kein Grund"', () => {
        const { container } = render(
            <TaskSourcePicker
                options={[{ key: 'manager', label: 'Manager', notPlannableReason: null }]}
                onPick={vi.fn()}
            />,
        );

        expect(container.querySelectorAll('[data-slot="task-source-not-plannable"]')).toHaveLength(
            0,
        );
    });

    /**
     * Die Zeile bleibt anklickbar. Suchen funktioniert, und das ist der Wert
     * der Foederation — ihn wegzunehmen waere schlimmer als die Absage am Ende.
     */
    it('bleibt waehlbar — kein disabled, kein aria-disabled', () => {
        const gewaehlt = vi.fn();
        const { container } = render(<TaskSourcePicker options={MIT_GRUND} onPick={gewaehlt} />);

        const zeile = container.querySelector<HTMLButtonElement>('[data-source="verwaltung"]')!;

        expect(zeile.disabled).toBe(false);
        expect(zeile.getAttribute('aria-disabled')).toBeNull();

        fireEvent.click(zeile);

        expect(gewaehlt).toHaveBeenCalledWith(MIT_GRUND[1]);
    });

    /**
     * Kein Tooltip: Was nur beim Daraufzeigen erscheint, gibt es auf dem Handy
     * nicht. Der Satz steht da, bevor jemand irgendwohin zeigt.
     */
    it('sagt es ohne Daraufzeigen', () => {
        const { container } = render(<TaskSourcePicker options={MIT_GRUND} onPick={vi.fn()} />);

        const zeile = container.querySelector('[data-source="verwaltung"]')!;

        expect(zeile.getAttribute('title')).toBeNull();
        expect(zeile.querySelector('[data-slot="task-source-not-plannable"]')?.textContent).toBe(
            'Aus der Verwaltung laesst sich hier noch nichts einplanen.',
        );
    });

    /**
     * Und er wird mit vorgelesen: Eine Absage, die nur zu sehen ist, waere fuer
     * Hilfsmittel wieder der Weg ueber vier Stufen ins Leere.
     */
    it('steht im Namen, den Hilfsmittel lesen', () => {
        render(<TaskSourcePicker options={MIT_GRUND} onPick={vi.fn()} />);

        expect(
            screen.getByRole('button', { name: /Aus der Verwaltung laesst sich hier noch nichts/ }),
        ).toBeTruthy();
    });
});
