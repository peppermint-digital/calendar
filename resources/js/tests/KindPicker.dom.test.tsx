/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { KindPicker, type KindOption } from '../src/KindPicker';

/**
 * Die Wahl der Terminart (#9796).
 *
 * Die Entscheidung dahinter, woertlich von Bastian am 11.11.2026: „Ich würde die
 * Systemwahl außen vor lassen und sie nur in Klammern an der anderen Auswahl
 * anzeigen. […] So dass ich immer alle Arten direkt sehen kann."
 */
const AUSWAHL: KindOption[] = [
    { source: '', system: '', kind: { key: 'crm_termin', label: 'Termin' } },
    { source: 'manager', system: 'Manager', kind: { key: 'business', label: 'Geschäftlich' } },
    { source: 'manager', system: 'Manager', kind: { key: 'deadline', label: 'Frist' } },
    { source: 'brain', system: 'AI Brain', kind: { key: 'private', label: 'Privat' } },
];

afterEach(cleanup);

function zeilen(): string[] {
    return Array.from(document.querySelectorAll('[data-slot="kind-picker"] button')).map((knopf) =>
        (knopf.textContent ?? '').trim(),
    );
}

describe('die Liste', () => {
    it('zeichnet jede Art als eigene Zeile, in der gegebenen Reihenfolge', () => {
        render(<KindPicker options={AUSWAHL} onPick={vi.fn()} />);

        // Hier wird NICHT sortiert. Nach System zu sortieren zerlegte die Liste
        // wieder in die Produktkaestchen, die sie gerade aufloest (Wiki #595).
        expect(zeilen()).toEqual(['Termin', 'Geschäftlich(Manager)', 'Frist(Manager)', 'Privat(AI Brain)']);
    });

    it('fuehrt keine Koepfe, unter denen die Arten eines Systems stehen', () => {
        const { container } = render(<KindPicker options={AUSWAHL} onPick={vi.fn()} />);

        // Eine Liste, nicht vier Kaesten: Das System ist Beschriftung und
        // nicht Gliederung. Deshalb genau EINE Liste im Bauteil.
        expect(container.querySelectorAll('ul')).toHaveLength(1);
        expect(container.querySelectorAll('[data-slot="kind-picker"] li')).toHaveLength(4);
    });

    it('laesst den Systemnamen bei eigenen Arten weg', () => {
        render(<KindPicker options={AUSWAHL} onPick={vi.fn()} />);

        // Der Name beantwortet „wo entsteht der Termin?". Bei den eigenen Arten
        // ist die Antwort „hier", und das ist die Vorgabe.
        expect(zeilen()[0]).toBe('Termin');
        expect(zeilen()[0]).not.toContain('(');
    });

    it('gibt die ganze Wahl heraus, nicht nur die Art', () => {
        const gewaehlt = vi.fn();

        render(<KindPicker options={AUSWAHL} onPick={gewaehlt} />);

        fireEvent.click(screen.getByRole('button', { name: /Privat/ }));

        // Mit dem System: Die Art allein genuegt nicht, denn zwei Systeme
        // duerfen denselben Schluessel fuehren.
        expect(gewaehlt).toHaveBeenCalledWith(AUSWAHL[3]);
    });

    it('trennt zwei Systeme mit demselben Artenschluessel', () => {
        const gewaehlt = vi.fn();
        const doppelt: KindOption[] = [
            { source: 'manager', system: 'Manager', kind: { key: 'business', label: 'Geschäftlich' } },
            { source: 'verwaltung', system: 'Verwaltung', kind: { key: 'business', label: 'Geschäftlich' } },
        ];

        render(<KindPicker options={doppelt} onPick={gewaehlt} />);

        expect(zeilen()).toEqual(['Geschäftlich(Manager)', 'Geschäftlich(Verwaltung)']);

        fireEvent.click(screen.getByRole('button', { name: /Verwaltung/ }));
        expect(gewaehlt).toHaveBeenCalledWith(doppelt[1]);
    });

    it('markiert die getroffene Wahl', () => {
        render(
            <KindPicker options={AUSWAHL} value={{ source: 'manager', kind: 'business' }} onPick={vi.fn()} />,
        );

        expect(screen.getByRole('button', { name: /Geschäftlich/ }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByRole('button', { name: 'Termin' }).getAttribute('aria-pressed')).toBe('false');
    });
});

describe('ein Ziel, das nicht geantwortet hat', () => {
    it('steht als Hinweis mit Namen da, waehrend der Rest waehlbar bleibt', () => {
        render(<KindPicker options={AUSWAHL} unreachable={['Verwaltung']} onPick={vi.fn()} />);

        // „Niemand ist zustaendig" und „ich konnte nicht nachsehen" sind zwei
        // Aussagen (Wiki #595).
        expect(screen.getByRole('status').textContent).toContain('Verwaltung');
        expect(zeilen()).toHaveLength(4);
    });

    it('sagt es auch, wenn ueberhaupt keine Art uebrig ist', () => {
        render(<KindPicker options={[]} unreachable={['Manager', 'Verwaltung']} onPick={vi.fn()} />);

        // Ein Ausfall, der als leere Liste erscheint, sieht aus wie eine
        // Auskunft — und schickt den naechsten Menschen auf die falsche Suche.
        const hinweis = screen.getByRole('status');
        expect(hinweis.textContent).toContain('Manager');
        expect(hinweis.textContent).toContain('Verwaltung');
    });

    it('schweigt, wenn niemand fehlt', () => {
        render(<KindPicker options={AUSWAHL} onPick={vi.fn()} />);

        expect(screen.queryByRole('status')).toBeNull();
    });
});

describe('solange geladen wird', () => {
    it('sagt, dass noch etwas unterwegs ist', () => {
        render(<KindPicker options={AUSWAHL} loading onPick={vi.fn()} />);

        expect(screen.getByText(/werden geladen/)).toBeTruthy();
    });

    it('zeigt den Leer-Satz NICHT, solange noch geladen wird', () => {
        // Sonst stuende „hier laesst sich keine Terminart anlegen" da, waehrend
        // die Antwort noch unterwegs ist — eine Aussage, die niemand geprueft hat.
        render(<KindPicker options={[]} loading onPick={vi.fn()} />);

        expect(screen.queryByText(/keine Terminart anlegen/)).toBeNull();
    });

    it('sagt es, wenn es wirklich nichts zu waehlen gibt', () => {
        render(<KindPicker options={[]} onPick={vi.fn()} />);

        expect(screen.getByText(/keine Terminart anlegen/)).toBeTruthy();
    });
});

describe('die Woerter', () => {
    it('kommen vom Produkt, wenn es sie mitgibt', () => {
        render(
            <KindPicker
                options={AUSWAHL}
                unreachable={['Manager']}
                onPick={vi.fn()}
                labels={{ heading: 'Welche Art?', unreachable: 'Ohne Antwort: {systems}' }}
            />,
        );

        expect(screen.getByText('Welche Art?')).toBeTruthy();
        expect(screen.getByRole('status').textContent).toBe('Ohne Antwort: Manager');
    });
});
