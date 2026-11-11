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
    /**
     * Umgeschrieben am 11.11.2026 (#9798), nicht geloescht: Der Gegenstand ist
     * derselbe — die Kennung wird gemeldet. Dazu reist jetzt der ganze Eintrag
     * mit, weil der Aufrufer mehr ueber die Wahl sagen will als ihre Kennung:
     * Im Assistenten steht der TITEL der gewaehlten Aufgabe im Kopf, waehrend
     * der Zeitraum bestaetigt wird. Ihn aus der Kennung zurueckzusuchen hiesse,
     * die Trefferliste ein zweites Mal zu halten.
     *
     * Additiv bleibt es, weil der zweite Parameter am Typ optional ist: Wer nur
     * die Kennung nimmt, merkt nichts davon.
     */
    it('meldet die Kennung des gewaehlten Eintrags — und den Eintrag dazu', async () => {
        const gewaehlt = vi.fn();
        render(<PlannableSearch search={() => Promise.resolve(offen)} onPick={gewaehlt} />);

        (await screen.findByText('Abgabe vorbereiten')).closest('button')!.click();

        expect(gewaehlt).toHaveBeenCalledWith(7, offen[0]);
        expect(gewaehlt.mock.calls[0][0]).toBe(7);
    });

    /** Fremde Systeme vergeben Zeichenketten als Kennung, nicht nur Zahlen. */
    it('nimmt auch eine Zeichenkette als Kennung', async () => {
        const gewaehlt = vi.fn();
        const stoerung = { id: 'ticket:42', title: 'Störung' };

        render(<PlannableSearch search={() => Promise.resolve([stoerung])} onPick={gewaehlt} />);

        (await screen.findByText('Störung')).closest('button')!.click();

        expect(gewaehlt).toHaveBeenCalledWith('ticket:42', stoerung);
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

/**
 * Der Systembegriff (#9798).
 *
 * Die Suche kannte bis zum 11.11.2026 keine Systeme: `search` war eine Funktion
 * mit einem Argument, `items` eine flache Liste. Mit mehreren Aufgabensystemen
 * geht das nicht mehr — und zwar aus zwei Gruenden, von denen der zweite der
 * wichtigere ist: Kennungen sind nur INNERHALB eines Systems eindeutig, und ein
 * System, das nicht antwortet, ist etwas anderes als ein System ohne offene
 * Aufgaben (Wiki #595).
 *
 * Beides ist ADDITIV gebaut. Die Tests darueber (eine Suche mit einem Argument,
 * die einen nackten Array liefert) laufen unveraendert weiter — das ist die
 * Zusicherung fuer CRM, Verwaltung und Brain, die heute genau so aufrufen.
 */
describe('das gewaehlte System', () => {
    it('reicht es an die Suche durch', async () => {
        const gefragt: Array<unknown[]> = [];
        const suche = (...argumente: unknown[]) => {
            gefragt.push(argumente);

            return Promise.resolve(offen);
        };

        render(<PlannableSearch search={suche} source="manager" onPick={() => {}} />);

        await waitFor(() => expect(gefragt.length).toBeGreaterThan(0));
        expect(gefragt[0]).toEqual(['', 'manager']);
    });

    /**
     * Bastians Vorgabe: „Natuerlich darf ich nur meine Aufgaben sehen." Die
     * Maske darf nicht dazu einladen — kein Feld fuer eine Person, und auch
     * kein Parameter dafuer. Die angemeldete Person ist die Person.
     */
    it('fragt nach Begriff und System und nach nichts sonst', async () => {
        const gefragt: Array<unknown[]> = [];
        const suche = (...argumente: unknown[]) => {
            gefragt.push(argumente);

            return Promise.resolve(offen);
        };

        render(<PlannableSearch search={suche} source="manager" onPick={() => {}} />);

        await waitFor(() => expect(gefragt.length).toBeGreaterThan(0));
        expect(gefragt[0]).toHaveLength(2);
        expect(document.querySelectorAll('[data-slot="plannable-search"] input')).toHaveLength(1);
    });

    it('nimmt auch die Ernte-Form an, nicht nur einen Array', async () => {
        render(
            <PlannableSearch
                search={async () => ({ tasks: offen, ok: ['manager'] })}
                source="manager"
                onPick={() => {}}
            />,
        );

        expect(await screen.findByText('Abgabe vorbereiten')).toBeTruthy();
    });

    /**
     * Sonst stuenden die Aufgaben des Managers unter der Ueberschrift der
     * Verwaltung — und man koennte sie anklicken und im falschen System
     * einplanen.
     */
    it('wirft die Treffer des vorigen Systems weg, sobald ein anderes gewaehlt ist', async () => {
        const pro: Record<string, Plannable[]> = {
            manager: [{ id: 1, title: 'Aus dem Manager' }],
            verwaltung: [{ id: 2, title: 'Aus der Verwaltung' }],
        };

        const { rerender } = render(
            <PlannableSearch
                search={async (_begriff, quelle) => pro[quelle ?? ''] ?? []}
                source="manager"
                onPick={() => {}}
            />,
        );

        expect(await screen.findByText('Aus dem Manager')).toBeTruthy();

        rerender(
            <PlannableSearch
                search={async (_begriff, quelle) => pro[quelle ?? ''] ?? []}
                source="verwaltung"
                onPick={() => {}}
            />,
        );

        expect(await screen.findByText('Aus der Verwaltung')).toBeTruthy();
        expect(screen.queryByText('Aus dem Manager')).toBeNull();
    });
});

/**
 * Die drei Zustaende, die NICHT dasselbe sind (Wiki #595).
 *
 * „Niemand ist zustaendig" und „ich konnte nicht nachsehen" sind zwei
 * Aussagen. Ein Systemausfall darf nicht als leere Liste erscheinen — sonst
 * sieht er aus wie eine Auskunft und schickt den naechsten Menschen auf die
 * falsche Suche.
 */
describe('die drei Zustaende', () => {
    it('sagt „nichts offen", wenn das System geantwortet und nichts gemeldet hat', async () => {
        render(
            <PlannableSearch
                search={async () => ({ tasks: [], ok: ['manager'] })}
                source="manager"
                onPick={() => {}}
                emptyText="Keine offenen Aufgaben."
            />,
        );

        expect(await screen.findByText('Keine offenen Aufgaben.')).toBeTruthy();
        expect(screen.queryByRole('status')).toBeNull();
    });

    it('sagt den GRUND, wenn das System nicht geantwortet hat', async () => {
        render(
            <PlannableSearch
                search={async () => ({
                    tasks: [],
                    ok: [],
                    failed: { manager: 'Verbindung abgelehnt' },
                })}
                source="manager"
                sourceNames={{ manager: 'Manager' }}
                onPick={() => {}}
                emptyText="Keine offenen Aufgaben."
            />,
        );

        const hinweis = await screen.findByRole('status');

        // Im Wortlaut der Gegenstelle — und mit dem LOKALEN Namen des Systems,
        // nicht mit seinem Schluessel.
        expect(hinweis.textContent).toContain('Verbindung abgelehnt');
        expect(hinweis.textContent).toContain('Manager');
        expect(hinweis.textContent).not.toContain('manager:');

        // Und NICHT die Leermeldung: Das waere die Auskunft, die hier keiner
        // geben kann.
        expect(screen.queryByText('Keine offenen Aufgaben.')).toBeNull();
    });

    it('sagt nichts von beidem, solange die Ernte laeuft', () => {
        render(
            <PlannableSearch
                search={() => new Promise(() => {})}
                source="manager"
                onPick={() => {}}
            />,
        );

        // Beides waere eine Aussage ueber etwas, das noch unterwegs ist.
        expect(screen.queryByTestId('plannable-empty')).toBeNull();
        expect(screen.queryByRole('status')).toBeNull();
    });

    /**
     * Der Fall, auf den es bei mehreren Systemen ankommt: Ein Ausfall nimmt
     * niemandem die Liste weg.
     */
    it('laesst ein ausgefallenes System die anderen nicht verdecken', async () => {
        render(
            <PlannableSearch
                search={async () => ({
                    tasks: [
                        { id: 1, title: 'Aus dem Manager', sourceKey: 'manager' },
                        { id: 1, title: 'Aus dem Brain', sourceKey: 'brain' },
                    ],
                    ok: ['manager', 'brain'],
                    failed: { verwaltung: 'Zeitüberschreitung' },
                })}
                sourceNames={{ verwaltung: 'Verwaltung' }}
                onPick={() => {}}
            />,
        );

        // Beide Treffer stehen da — auch wenn sie dieselbe Kennung fuehren.
        expect(await screen.findByText('Aus dem Manager')).toBeTruthy();
        expect(screen.getByText('Aus dem Brain')).toBeTruthy();

        const hinweis = screen.getByRole('status');
        expect(hinweis.textContent).toContain('Verwaltung');
        expect(hinweis.textContent).toContain('Zeitüberschreitung');
    });

    /**
     * Hat NIEMAND geantwortet, ohne dass ein Ausfall gemeldet waere, wissen wir
     * nichts — und dann steht hier nichts. Eine Leermeldung waere eine
     * Auskunft, die keiner geprueft hat.
     */
    it('erfindet keine Auskunft, wenn kein System geantwortet hat', async () => {
        render(
            <PlannableSearch
                search={async () => ({ tasks: [], ok: [] })}
                source="manager"
                onPick={() => {}}
            />,
        );

        await waitFor(() =>
            expect(document.querySelector('[data-slot="plannable-search"]')).toBeTruthy(),
        );
        expect(screen.queryByTestId('plannable-empty')).toBeNull();
    });

    /**
     * Und ohne jede Angabe ueber die Systeme bleibt es bei der alten Lesart:
     * eine Antwort ist eine Antwort. Sonst verschwaende ein Produkt, das nur
     * Treffer schickt, ploetzlich seine Leermeldung.
     */
    it('behaelt die alte Lesart, wenn die Antwort nichts ueber Systeme sagt', async () => {
        render(<PlannableSearch search={async () => ({ tasks: [] })} onPick={() => {}} />);

        expect(await screen.findByTestId('plannable-empty')).toBeTruthy();
    });
});

/**
 * Was eine Aufgabe traegt und was beim Planen hilft (#9798).
 *
 * `description` und `project_id` sind Vokabular des Aufgabensystems und
 * gehoeren nicht in den Kalender. Wer mehr wissen muss, als fuer die Wahl
 * noetig ist, geht hin — dafuer ist `url` da.
 */
describe('was an einem Treffer steht', () => {
    const reich: Plannable = {
        id: 7,
        title: 'Abgabe vorbereiten',
        kindLabel: 'Abgabe',
        urgency: 'hoch',
        due: '12.03.2026',
        url: 'https://manager.example/aufgaben/7',
    };

    it('zeigt die Art der Aufgabe', async () => {
        render(<PlannableSearch search={async () => [reich]} onPick={() => {}} />);

        expect(await screen.findByText('Abgabe')).toBeTruthy();
        expect(screen.getByText('hoch')).toBeTruthy();
        expect(screen.getByText('fällig 12.03.2026')).toBeTruthy();
    });

    it('fuehrt den Weg ins Heimatsystem', async () => {
        render(<PlannableSearch search={async () => [reich]} onPick={() => {}} />);

        const weg = await screen.findByRole('link', { name: /Abgabe vorbereiten/ });
        expect(weg.getAttribute('href')).toBe('https://manager.example/aufgaben/7');
    });

    /**
     * Ein Link IM Knopf waere verschachtelt bedienbar: Der Klick trifft beides,
     * und ein Vorleser kann die zwei Handlungen nicht trennen. Hier sind es
     * zwei Flaechen mit zwei Zwecken — einplanen, oder nachsehen gehen.
     */
    it('legt ihn NEBEN den Wahlknopf, nicht hinein', async () => {
        render(<PlannableSearch search={async () => [reich]} onPick={() => {}} />);

        await screen.findByRole('link', { name: /Abgabe vorbereiten/ });
        expect(document.querySelectorAll('button a')).toHaveLength(0);
    });

    it('laesst ihn weg, wenn die Aufgabe keinen nennt', async () => {
        render(<PlannableSearch search={async () => [offen[1]!]} onPick={() => {}} />);

        await screen.findByText('Rechnung pruefen');
        expect(screen.queryByRole('link')).toBeNull();
    });
});
