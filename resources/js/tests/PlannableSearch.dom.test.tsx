/**
 * @vitest-environment jsdom
 */
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { PlannableSearch, type Plannable } from '../src/PlannableSearch';

afterEach(cleanup);

const offen: Plannable[] = [
    { id: 7, title: 'Abgabe vorbereiten', context: 'Website', urgency: 'hoch', due: '12.03.2026' },
    { id: 9, title: 'Rechnung pruefen' },
];

describe('die Trefferliste', () => {
    it('zeigt, was der Eintrag mitbringt', async () => {
        render(<PlannableSearch search={() => Promise.resolve(offen)} onPick={() => {}} />);

        expect(await screen.findByText('Abgabe vorbereiten')).toBeTruthy();
        expect(screen.getByText('Website')).toBeTruthy();
        expect(screen.getByText('hoch')).toBeTruthy();
        expect(screen.getByText('fällig 12.03.2026')).toBeTruthy();
    });

    /** Sonst stuende an jedem knappen Treffer ein leeres Kaestchen. */
    it('laesst die Zeile weg, wenn der Eintrag nichts dazu sagt', async () => {
        render(<PlannableSearch search={() => Promise.resolve([offen[1]!])} onPick={() => {}} />);

        await screen.findByText('Rechnung pruefen');
        expect(document.querySelectorAll('[data-slot="plannable-search"] button div')).toHaveLength(1);
    });

    it('sagt es, wenn nichts gefunden wurde', async () => {
        render(<PlannableSearch search={() => Promise.resolve([])} onPick={() => {}} emptyText="Nichts offen." />);

        expect(await screen.findByText('Nichts offen.')).toBeTruthy();
    });

    /**
     * Ein Wurf hier riss frueher den ganzen Dialog mit, in dem die Suche
     * steht — eine leere Liste ist die ehrlichere Antwort.
     */
    it('bleibt stehen, wenn die Suche scheitert', async () => {
        render(<PlannableSearch search={() => Promise.reject(new Error('weg'))} onPick={() => {}} />);

        expect(await screen.findByText('Nichts Offenes gefunden.')).toBeTruthy();
    });
});

describe('die Auswahl', () => {
    it('meldet die Kennung des gewaehlten Eintrags', async () => {
        const gewaehlt = vi.fn();
        render(<PlannableSearch search={() => Promise.resolve(offen)} onPick={gewaehlt} />);

        (await screen.findByText('Abgabe vorbereiten')).closest('button')!.click();

        expect(gewaehlt).toHaveBeenCalledWith(7);
    });

    /** Fremde Systeme vergeben Zeichenketten als Kennung, nicht nur Zahlen. */
    it('nimmt auch eine Zeichenkette als Kennung', async () => {
        const gewaehlt = vi.fn();
        render(
            <PlannableSearch
                search={() => Promise.resolve([{ id: 'ticket:42', title: 'Störung' }])}
                onPick={gewaehlt}
            />,
        );

        (await screen.findByText('Störung')).closest('button')!.click();

        expect(gewaehlt).toHaveBeenCalledWith('ticket:42');
    });
});

describe('die Suche selbst', () => {
    /**
     * Ein fester Pfad im Paket haette alle Produkte gezwungen, denselben
     * Endpunkt zu bauen — und das Paket haette etwas ueber ihre Datenbank
     * gewusst. Deshalb eine Funktion.
     */
    it('fragt mit leerem Begriff an und reicht das Getippte durch', async () => {
        const gefragt: string[] = [];
        const suche = (begriff: string) => {
            gefragt.push(begriff);

            return Promise.resolve(offen);
        };

        render(<PlannableSearch search={suche} onPick={() => {}} />);

        await waitFor(() => expect(gefragt).toEqual(['']));
    });

    it('nimmt eine eigene Beschriftung an', () => {
        render(<PlannableSearch search={() => Promise.resolve([])} onPick={() => {}} placeholder="Ticket suchen" />);

        expect(screen.getByPlaceholderText('Ticket suchen')).toBeTruthy();
    });
});

describe('Ladezustand (#8307)', () => {
    it('zeigt „nichts gefunden" NICHT, solange die erste Antwort aussteht', async () => {
        // Eine Suche, die haengt: Vorher stand hier sofort „Nichts Offenes
        // gefunden" — ein Ergebnis, wo noch keines vorlag. Wer klickt und
        // schnell liest, haelt das fuer die Antwort.
        render(<PlannableSearch search={() => new Promise(() => {})} onPick={vi.fn()} />);

        expect(screen.queryByTestId('plannable-empty')).toBeNull();
    });

    it('zeigt sie, sobald die Antwort leer zurueckkommt', async () => {
        render(<PlannableSearch search={async () => []} onPick={vi.fn()} />);

        expect(await screen.findByTestId('plannable-empty')).toBeTruthy();
    });

    it('zeigt sie nicht, wenn die Antwort Treffer enthaelt', async () => {
        render(
            <PlannableSearch
                search={async () => [{ id: 1, title: 'Serverumzug vorbereiten' }]}
                onPick={vi.fn()}
            />,
        );

        expect(await screen.findByText('Serverumzug vorbereiten')).toBeTruthy();
        expect(screen.queryByTestId('plannable-empty')).toBeNull();
    });
});
