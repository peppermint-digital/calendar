/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    PlannableSearch,
    type Plannable,
    type PlannableFailure,
    type PlannableFailureReason,
} from '../src/PlannableSearch';

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

    /**
     * Umgeschrieben am 11.11.2026 (#9810): Der Ausfall war ein Satz
     * (`failed: {schluessel: text}`) und ist jetzt ein Objekt mit typisiertem
     * Grund (`failures`). Das neue Verhalten ist richtig, weil ein Satz die
     * vier Lagen zusammenwirft — und eine Maske, die sie nicht auseinanderhalten
     * kann, bietet ein Wiederholen fuer eine fehlende Berechtigung an.
     *
     * Was dieser Test festhaelt, ist unveraendert: Es steht der WORTLAUT der
     * Gegenstelle da, und der LOKALE Name des Systems.
     */
    it('sagt den GRUND, wenn das System nicht geantwortet hat', async () => {
        render(
            <PlannableSearch
                search={async () => ({
                    tasks: [],
                    ok: [],
                    failures: {
                        manager: {
                            source: 'manager',
                            reason: 'unreachable',
                            message: 'Verbindung abgelehnt',
                            may_retry: true,
                        },
                    },
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
                    // Dieselbe Umschreibung wie oben (#9810): Objekt statt
                    // Satz. Festgehalten wird weiter, dass ein Ausfall
                    // niemandem die Liste wegnimmt.
                    failures: {
                        verwaltung: {
                            source: 'verwaltung',
                            reason: 'unreachable',
                            message: 'Zeitüberschreitung',
                            may_retry: true,
                        },
                    },
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

/**
 * Die vier Gruende sind vier naechste Schritte (#9810).
 *
 * Bis zum 11.11.2026 trug die Ernte `failed: {schluessel: satz}` — ein Satz fuer
 * jede Lage. Drueben im Aufgabenpaket unterscheidet `SourceFailure` aber vier:
 * „nicht angeboten", „nicht erlaubt", „nicht erreichbar", „der Dienst hat einen
 * Fehler gemeldet". Sie fuehren zu vier verschiedenen Dingen — abwarten, eine
 * Berechtigung holen, das andere System ansehen, es melden —, und eine Zeile
 * fuer alle vier sagt „etwas ist schiefgelaufen" und damit nichts.
 *
 * Die Namen sind hier dieselben wie dort, damit das Produkt durchreicht und
 * nichts uebersetzt.
 */
describe('die vier Ausfallgruende', () => {
    /** Ein Ausfall, wie die Gegenstelle ihn schickt. */
    function ausfall(
        reason: PlannableFailureReason | null,
        message: string,
        may_retry = false,
    ): Record<string, PlannableFailure> {
        return { verwaltung: { source: 'verwaltung', reason, message, may_retry } };
    }

    function zeige(failures: Record<string, PlannableFailure>) {
        return render(
            <PlannableSearch
                search={async () => ({ tasks: [], ok: [], failures })}
                source="verwaltung"
                sourceNames={{ verwaltung: 'Verwaltung' }}
                onPick={() => {}}
                emptyText="Nichts offen."
            />,
        );
    }

    /** „Bietet keine Aufgaben an" ist kein Fehler, sondern eine Auskunft. */
    it('sagt bei not_offered, dass das System keine Aufgaben fuehrt', async () => {
        zeige(ausfall('not_offered', 'tasks.list wird nicht angeboten'));

        const hinweis = await screen.findByRole('status');
        expect(hinweis.textContent).toContain('Verwaltung bietet keine Aufgaben an.');

        // Und kein Wiederholen: Daran aendert Warten nichts.
        expect(hinweis.querySelector('[data-action="retry"]')).toBeNull();
    });

    /** Fehlende Berechtigung wird durch Warten nicht besser. */
    it('sagt bei not_allowed, dass die Berechtigung fehlt', async () => {
        zeige(ausfall('not_allowed', 'Kein Zugriff auf tasks.list'));

        const hinweis = await screen.findByRole('status');
        expect(hinweis.textContent).toContain('Keine Berechtigung für Verwaltung');
        expect(hinweis.textContent).toContain('Kein Zugriff auf tasks.list');
        expect(hinweis.querySelector('[data-action="retry"]')).toBeNull();
    });

    it('sagt bei unreachable, dass niemand geantwortet hat', async () => {
        zeige(ausfall('unreachable', 'Zeitüberschreitung nach 5s', true));

        const hinweis = await screen.findByRole('status');
        expect(hinweis.textContent).toContain('Nicht erreichbar: Verwaltung');
        expect(hinweis.textContent).toContain('Zeitüberschreitung nach 5s');
    });

    it('sagt bei tool_failed, dass der Dienst selbst einen Fehler gemeldet hat', async () => {
        zeige(ausfall('tool_failed', 'SQLSTATE[42S02]', true));

        const hinweis = await screen.findByRole('status');
        expect(hinweis.textContent).toContain('Verwaltung hat einen Fehler gemeldet');
        expect(hinweis.textContent).toContain('SQLSTATE[42S02]');
    });

    /**
     * Ohne genannten Grund steht der WORTLAUT da — er ist das Einzige, was
     * dann etwas sagt.
     *
     * Und darum gibt es kein fuenftes „unknown": Das waere ein Grund, der wie
     * einer aussieht und keiner ist. Er bekaeme in der Tabelle einen Satz, und
     * der Satz sagte nichts. `null` zwingt auf den Wortlaut.
     */
    it('zeigt bei reason null den Wortlaut der Gegenstelle', async () => {
        zeige(ausfall(null, 'Verwaltung antwortet nicht: Host unbekannt'));

        const hinweis = await screen.findByRole('status');
        expect(hinweis.textContent).toContain('Verwaltung antwortet nicht: Host unbekannt');

        // „Nicht erreichbar" waere schon mehr, als geprueft ist.
        expect(hinweis.textContent).not.toContain('Nicht erreichbar');
        expect(hinweis.querySelector('p')?.getAttribute('data-reason')).toBe('');
    });

    /** Fuer eine Maske, die „nicht angeboten" anders zeichnen will als einen Fehler. */
    it('schreibt den Grund auch an das Element', async () => {
        zeige(ausfall('tool_failed', 'kaputt'));

        const hinweis = await screen.findByRole('status');
        // Innerhalb der Meldung gesucht: Die Huelle der Suche traegt dasselbe
        // `data-source`, weil dort das gewaehlte System steht.
        expect(hinweis.querySelector('[data-source="verwaltung"]')?.getAttribute('data-reason')).toBe(
            'tool_failed',
        );
    });

    it('laesst jeden der Saetze ueberschreiben', async () => {
        render(
            <PlannableSearch
                search={async () => ({
                    tasks: [],
                    ok: [],
                    failures: { verwaltung: { source: 'verwaltung', reason: 'not_offered', message: 'x', may_retry: false } },
                })}
                source="verwaltung"
                sourceNames={{ verwaltung: 'Verwaltung' }}
                onPick={() => {}}
                notOfferedText="{system} plant hier nichts ein."
            />,
        );

        expect(await screen.findByText('Verwaltung plant hier nichts ein.')).toBeTruthy();
    });
});

/**
 * `may_retry` wird DURCHGEREICHT, nicht nachgerechnet (#9810).
 *
 * Die Regel „Warten hilft" gehoert dem Zielsystem
 * (`SourceFailure::mightPassByItself()`). Eine Maske, die sie aus dem Grund
 * nachbaut, muss ein zweites Mal korrigiert werden, sobald ein fuenfter Grund
 * dazukommt — und widerspricht dem Zielsystem in der Zwischenzeit.
 */
describe('das Wiederholen', () => {
    it('bietet es an, wo das Zielsystem es erlaubt', async () => {
        render(
            <PlannableSearch
                search={async () => ({
                    tasks: [],
                    ok: [],
                    failures: {
                        verwaltung: {
                            source: 'verwaltung',
                            reason: 'unreachable',
                            message: 'Zeitüberschreitung',
                            may_retry: true,
                        },
                    },
                })}
                source="verwaltung"
                onPick={() => {}}
                retryText="Nochmal"
            />,
        );

        expect(await screen.findByRole('button', { name: 'Nochmal' })).toBeTruthy();
    });

    /**
     * Der Fall, der das Nachrechnen von Durchreichen unterscheidet: Der Grund
     * sagt „aussichtslos", das Zielsystem sagt „versuch es". Es gilt das
     * Zielsystem.
     */
    it('folgt dem Zielsystem und nicht dem Grund', async () => {
        render(
            <PlannableSearch
                search={async () => ({
                    tasks: [],
                    ok: [],
                    failures: {
                        verwaltung: {
                            source: 'verwaltung',
                            // Nach der Regel des Aufgabenpakets waere das NICHT
                            // wiederholbar. Hier sagt die Antwort etwas anderes.
                            reason: 'not_allowed',
                            message: 'Berechtigung wird gerade erteilt',
                            may_retry: true,
                        },
                    },
                })}
                source="verwaltung"
                onPick={() => {}}
            />,
        );

        expect(await screen.findByRole('button', { name: 'Erneut versuchen' })).toBeTruthy();
    });

    /**
     * Und der Klick fragt wirklich nochmal — ueber dieselbe `search`-Funktion,
     * die das Produkt ohnehin gibt. Kein zweiter Weg zu derselben Stelle.
     */
    it('fragt beim Klick noch einmal — und nimmt die Meldung zurueck, solange gefragt wird', async () => {
        let runde = 0;
        const suche = vi.fn(async () => {
            runde += 1;

            return runde === 1
                ? {
                      tasks: [],
                      ok: [],
                      failures: {
                          verwaltung: {
                              source: 'verwaltung',
                              reason: 'unreachable' as const,
                              message: 'Zeitüberschreitung',
                              may_retry: true,
                          },
                      },
                  }
                : { tasks: [{ id: 3, title: 'Ticket beantworten' }], ok: ['verwaltung'] };
        });

        render(<PlannableSearch search={suche} source="verwaltung" onPick={() => {}} />);

        fireEvent.click(await screen.findByRole('button', { name: 'Erneut versuchen' }));

        // Die zweite Runde ist die Antwort auf den Klick.
        expect(await screen.findByText('Ticket beantworten')).toBeTruthy();
        expect(suche).toHaveBeenCalledTimes(2);

        // Und die alte Meldung steht nicht mehr da — sie war die Auskunft von
        // vorher.
        expect(screen.queryByRole('status')).toBeNull();
    });
});

/**
 * `complete` sagt, ob die Liste vollstaendig ist (#9810).
 *
 * Drueben in `TaskHarvest::toArray()` steht es ausdruecklich und wird nicht dem
 * Leser ueberlassen. Hier gilt dasselbe: Eine Ernte, die sich selbst als
 * unvollstaendig meldet, darf nicht als „nichts offen" erscheinen — das waere
 * die Auskunft, die niemand geben kann (Wiki #595).
 */
describe('die Vollstaendigkeit', () => {
    it('sagt bei complete false kein „nichts offen"', async () => {
        render(
            <PlannableSearch
                search={async () => ({ tasks: [], ok: ['verwaltung'], complete: false })}
                source="verwaltung"
                onPick={() => {}}
                emptyText="Nichts offen."
            />,
        );

        await waitFor(() =>
            expect(document.querySelector('[data-slot="plannable-search"]')).toBeTruthy(),
        );

        expect(screen.queryByText('Nichts offen.')).toBeNull();
    });

    it('sagt es bei complete true', async () => {
        render(
            <PlannableSearch
                search={async () => ({ tasks: [], ok: ['verwaltung'], complete: true })}
                source="verwaltung"
                onPick={() => {}}
                emptyText="Nichts offen."
            />,
        );

        expect(await screen.findByText('Nichts offen.')).toBeTruthy();
    });
});

/**
 * Die ALTE Array-Form bleibt (#9810).
 *
 * Mit `failed` ist ein Name aus der Ernte-Form verschwunden, und das ist
 * Absicht: Wer ihn liest, soll einen Compilerfehler bekommen und kein stummes
 * „[object Object]".
 *
 * Warum das die Array-Form nicht beruehren KANN, und zwar nicht nur gerade
 * nicht: **`failed` lebte allein im Objektzweig. Der Array-Zweig setzt `ernte`
 * auf `null` und liest von der Antwort nichts als die Treffer selbst — es gibt
 * also keinen Pfad, auf dem das Entfernen eines Ernte-Feldes bei ihm ankommt.**
 * Die Union `Plannable[] | PlannableHarvest` und die Weiche `Array.isArray`
 * stehen unveraendert.
 *
 * Die Produkte geben heute genau diese Form herein. Diese Tests halten fest,
 * dass das so bleibt.
 */
describe('die alte Array-Form', () => {
    it('nimmt einen nackten Treffer-Array an', async () => {
        const suche: (begriff: string) => Promise<Plannable[]> = async () => offen;

        render(<PlannableSearch search={suche} onPick={() => {}} />);

        expect(await screen.findByText('Abgabe vorbereiten')).toBeTruthy();
        expect(screen.getByText('Rechnung pruefen')).toBeTruthy();
    });

    /**
     * Und sie behaelt die alte Lesart: Ein Array sagt ueber die Systeme nichts,
     * also gilt weiter „eine Antwort ist eine Antwort" — sonst verlore ein
     * Produkt, das nur Treffer schickt, ploetzlich seine Leermeldung.
     */
    it('sagt bei leerem Array weiter „nichts offen"', async () => {
        render(
            <PlannableSearch
                search={async () => []}
                source="verwaltung"
                onPick={() => {}}
                emptyText="Nichts offen."
            />,
        );

        expect(await screen.findByText('Nichts offen.')).toBeTruthy();
        expect(screen.queryByRole('status')).toBeNull();
    });

    /**
     * Der Satz selbst, festgehalten: Die Array-Form kommt am Ausfall-Teil gar
     * nicht vorbei. `ernte` ist `null`, also gibt es keine Ausfallzeile und
     * keinen Wiederhol-Knopf — unabhaengig davon, wie die Ernte-Form heute
     * heisst oder morgen heisst.
     */
    it('kommt an den Ausfaellen gar nicht vorbei', async () => {
        render(<PlannableSearch search={async () => offen} onPick={() => {}} />);

        await screen.findByText('Abgabe vorbereiten');

        expect(document.querySelector('[data-slot="plannable-failed"]')).toBeNull();
        expect(document.querySelector('[data-action="retry"]')).toBeNull();
        expect(screen.queryByRole('status')).toBeNull();
    });
});
