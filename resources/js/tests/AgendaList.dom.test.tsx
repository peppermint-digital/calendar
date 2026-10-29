/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { AgendaList, type AgendaRow } from '../src/AgendaList';

/**
 * Die Agenda-Liste — und vor allem ihr KLICK.
 *
 * Diese Datei hat bis zum 29.10.2026 gefehlt. Dreizehn andere Bauteile des
 * Pakets hatten einen DOM-Test, die Liste nicht — und sie ist das Bauteil, das
 * in allen vier Produkten den Klick auf eine Zeile besitzt.
 *
 * Aufgefallen ist es, als in AI Brain ein Klick auf eine eigene Zeile nicht
 * zur Einzelansicht führte. Dass man das überhaupt diskutieren musste, statt
 * eine Prüfung nachzusehen, war der eigentliche Befund: Der Klickweg ist genau
 * die Art Verdrahtung, die niemandem auffällt, wenn sie fehlt — man sieht eine
 * Liste, sie sieht richtig aus, und nichts passiert.
 */
const tag = (datum: string) => `Tag ${datum}`;

function zeile(ueber: Partial<AgendaRow<{ id: number }>> = {}): AgendaRow<{ id: number }> {
    return {
        key: 'z1',
        date: '2026-10-30',
        start: '10:15',
        end: '11:15',
        title: 'Zahnarzt',
        data: { id: 54 },
        ...ueber,
    };
}

afterEach(() => {
    cleanup();
});

describe('der Klick auf eine Zeile', () => {
    it('meldet die ganze Zeile, nicht nur ihre Kennung', () => {
        // Das Produkt entscheidet, was ein Klick bedeutet — dafür braucht es
        // die Zeile samt `data`. Eine Liste, die nur den Schlüssel meldet,
        // zwingt jedes Produkt, die Zeile ein zweites Mal zu suchen.
        const gewaehlt = vi.fn();

        render(<AgendaList rows={[zeile()]} dayLabel={tag} onSelect={gewaehlt} />);

        fireEvent.click(screen.getByText('Zahnarzt'));

        expect(gewaehlt).toHaveBeenCalledTimes(1);
        expect(gewaehlt.mock.calls[0][0]).toMatchObject({ key: 'z1', data: { id: 54 } });
    });

    it('feuert auch, wenn man die Uhrzeit oder das Etikett trifft', () => {
        // Der Klick hängt an der ganzen Zeile, nicht am Titel. Wer auf die
        // Uhrzeit zielt, hat dasselbe gemeint.
        const gewaehlt = vi.fn();

        render(<AgendaList rows={[zeile({ badge: 'Privat' })]} dayLabel={tag} onSelect={gewaehlt} />);

        fireEvent.click(screen.getByText('10:15–11:15'));
        fireEvent.click(screen.getByText('Privat'));

        expect(gewaehlt).toHaveBeenCalledTimes(2);
    });

    it('sieht ohne onSelect nicht klickbar aus', () => {
        // Eine Zeile, die klickbar aussieht und nichts tut, ist schlimmer als
        // eine, die es nicht aussieht: Die Oberfläche verspricht etwas, das sie
        // nicht hat.
        render(<AgendaList rows={[zeile()]} dayLabel={tag} />);

        const titel = screen.getByText('Zahnarzt');

        expect(titel.closest('div')?.className ?? '').not.toContain('cursor-pointer');
    });

    it('sieht mit onSelect klickbar aus', () => {
        render(<AgendaList rows={[zeile()]} dayLabel={tag} onSelect={vi.fn()} />);

        const streifen = screen.getByText('Zahnarzt').parentElement;

        expect(streifen?.className ?? '').toContain('cursor-pointer');
    });
});

describe('was die Zeile zeigt', () => {
    it('schreibt bei ganztaegig keine Uhrzeit', () => {
        render(<AgendaList rows={[zeile({ start: null, end: null })]} dayLabel={tag} />);

        // Kleingeschrieben, weil es die Vorgabe der Liste ist. Hier stand erst
        // „Ganztägig" — eine Annahme, und sie war falsch. Die Beschriftung ist
        // ueberschreibbar (`allDayLabel`), damit ein Produkt seine Sprache
        // behaelt; der Test prueft deshalb BEIDES.
        expect(screen.getByText('ganztägig')).toBeTruthy();
    });

    it('laesst das Produkt die Ganztags-Beschriftung setzen', () => {
        render(
            <AgendaList rows={[zeile({ start: null, end: null })]} dayLabel={tag} allDayLabel="Den ganzen Tag" />,
        );

        expect(screen.getByText('Den ganzen Tag')).toBeTruthy();
    });

    it('gruppiert nach Tag und beschriftet ihn, wie das Produkt es sagt', () => {
        render(
            <AgendaList
                rows={[zeile(), zeile({ key: 'z2', date: '2026-10-31', title: 'Danach' })]}
                dayLabel={tag}
            />,
        );

        expect(screen.getByText('Tag 2026-10-30')).toBeTruthy();
        expect(screen.getByText('Tag 2026-10-31')).toBeTruthy();
    });

    it('zeigt die Bedienelemente des Produkts am rechten Rand', () => {
        render(
            <AgendaList
                rows={[zeile()]}
                dayLabel={tag}
                actions={(row) => <button type="button">Öffnen {row.data?.id}</button>}
            />,
        );

        expect(screen.getByRole('button', { name: 'Öffnen 54' })).toBeTruthy();
    });

    it('laesst ein Bedienelement den Zeilen-Klick NICHT ausloesen', () => {
        /*
         * Der Fall, der sonst still falsch wird: Ein Klick auf „Öffnen" blubbert
         * an das Zeilen-DIV weiter und löst BEIDES aus — den Knopf und den
         * Zeilen-Klick. Bei zwei verschiedenen Zielen (Knopf: Aufgabe, Zeile:
         * Termin) gewinnt dann das zweite, und der Knopf tut scheinbar das
         * Falsche.
         */
        const gewaehlt = vi.fn();
        const gedrueckt = vi.fn();

        render(
            <AgendaList
                rows={[zeile()]}
                dayLabel={tag}
                onSelect={gewaehlt}
                actions={() => (
                    <button type="button" onClick={(e) => { e.stopPropagation(); gedrueckt(); }}>
                        Öffnen
                    </button>
                )}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Öffnen' }));

        expect(gedrueckt).toHaveBeenCalledTimes(1);
        expect(gewaehlt).not.toHaveBeenCalled();
    });

    it('zeigt den Hinweis, wenn nichts ansteht', () => {
        render(<AgendaList rows={[]} dayLabel={tag} empty={<p>Nichts</p>} />);

        expect(screen.getByText('Nichts')).toBeTruthy();
    });
});
